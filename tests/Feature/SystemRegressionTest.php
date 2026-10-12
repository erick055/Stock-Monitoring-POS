<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\CustomerReturn;
use App\Models\StockAlertSetting;
use App\Models\SupplierImport;
use App\Mail\InventoryAlertMail;
use App\Services\LowStockAlertService;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SystemRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_supplier_values_are_flagged_without_overflowing_import_storage(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $csv = "supplier_sku,product_name,unit_price,available_quantity\nBAD-PRICE,Invalid Price,1e100,-5\n";
        $this->actingAs($owner)->post(route('admin.suppliers.imports.upload'), [
            'supplier_name' => 'Test Supplier', 'supplier_code' => 'BAD-VALUES',
            'price_file' => UploadedFile::fake()->createWithContent('invalid-values.csv', $csv),
        ])->assertRedirect();
        $import = SupplierImport::firstOrFail();
        $this->assertSame(1, $import->error_count);
        $row = $import->rows()->firstOrFail();
        $this->assertSame('0.00', $row->unit_price);
        $this->assertNull($row->available_quantity);
        $this->assertNotEmpty($row->validation_errors);
        $this->post(route('admin.suppliers.imports.approve', $import))->assertSessionHasErrors();
        $this->assertDatabaseCount('supplier_prices', 0);
    }

    public function test_oversized_checkout_rolls_back_stock_and_returns_validation_error(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'BIG-TOTAL', 'name' => 'High Value Part', 'unit_price' => 6000000000, 'current_stock' => 5]);
        $this->actingAs($owner)->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson(route('admin.pos.checkout'), ['items' => [['product_id' => $product->product_id, 'quantity' => 2]]])
            ->assertUnprocessable()->assertJsonValidationErrors('checkout');
        $this->assertSame(5, $product->fresh()->current_stock);
        $this->assertDatabaseCount('sales_transactions', 0);
    }

    public function test_stock_movement_cannot_overflow_the_supported_balance(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'BIG-STOCK', 'name' => 'High Stock Part', 'current_stock' => 2147483647]);
        $this->actingAs($owner)->post(route('admin.inventory.movements.store'), [
            'product_id' => $product->product_id, 'movement_type' => 'in', 'quantity' => 1, 'reason_code' => 'PURCHASE_RECEIPT',
        ])->assertSessionHasErrors('quantity');
        $this->assertSame(2147483647, $product->fresh()->current_stock);
        $this->assertDatabaseCount('inventory_ledgers', 0);
    }

    public function test_failed_stock_alert_delivery_is_retried_on_the_next_check(): void
    {
        StockAlertSetting::create(['email_enabled' => true, 'notification_email' => 'alerts@example.com']);
        $product = Product::create(['sku' => 'RETRY-ALERT', 'name' => 'Retry Alert', 'current_stock' => 2, 'reorder_level' => 5]);
        $mailManager = Mail::getFacadeRoot();
        $pendingMail = \Mockery::mock(\Illuminate\Mail\PendingMail::class);
        $pendingMail->shouldReceive('send')->once()->andThrow(new \RuntimeException('Mail server unavailable'));
        Mail::shouldReceive('to')->once()->with('alerts@example.com')->andReturn($pendingMail);
        $alerts = app(LowStockAlertService::class);
        $this->assertSame(0, $alerts->checkProduct($product));
        $this->assertDatabaseHas('stock_alert_deliveries', ['product_id' => $product->product_id, 'status' => 'failed']);
        Mail::swap($mailManager);
        Mail::fake();
        $this->assertSame(1, $alerts->checkProduct($product));
        $this->assertSame(0, $alerts->checkProduct($product));
        Mail::assertSent(InventoryAlertMail::class, 1);
    }

    public function test_return_rate_counts_approved_receipts_once_and_excludes_rejected_returns(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RATE', 'name' => 'Rate Part']);
        $sale = SalesTransaction::create(['staff_id' => $owner->id, 'sale_date' => now(), 'payment_status' => 'paid']);
        foreach (['approved', 'approved', 'rejected', 'pending'] as $status) {
            CustomerReturn::create(['sale_id' => $sale->sale_id, 'product_id' => $product->product_id,
                'user_id' => $owner->id, 'quantity' => 1, 'reason' => 'Test', 'item_condition' => 'sellable',
                'status' => $status, 'returned_at' => now()]);
        }
        $summary = $this->actingAs($owner)->get(route('admin.returns'))->assertOk()->viewData('summary');
        $this->assertSame('2', $summary[0][1]);
        $this->assertSame('100.0%', $summary[2][1]);
    }

    public function test_duplicate_cart_lines_cannot_bypass_held_order_stock_checks(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create(['sku' => 'DUP', 'name' => 'Duplicate Part', 'current_stock' => 5, 'unit_price' => 100]);
        $payload = ['items' => array_fill(0, 2, ['product_id' => $product->product_id, 'quantity' => 4])];
        $this->actingAs($staff)->postJson(route('staff.pos.holds.store'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
        $this->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson(route('staff.pos.checkout'), $payload)->assertUnprocessable();
        $this->assertDatabaseCount('held_orders', 0);
        $this->assertDatabaseCount('sales_transactions', 0);
        $this->assertSame(5, $product->fresh()->current_stock);
    }

    public function test_multiple_free_bundles_require_enough_paid_companions(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $companion = Product::create(['sku' => 'PAID', 'name' => 'Paid Part', 'current_stock' => 10, 'unit_price' => 100]);
        $items = [['product_id' => $companion->product_id, 'quantity' => 1]];
        foreach (['FREE-A', 'FREE-B'] as $sku) {
            $product = Product::create(['sku' => $sku, 'name' => $sku, 'current_stock' => 5, 'unit_price' => 50]);
            ProductPromotion::create(['product_id' => $product->product_id, 'bundle_product_id' => $companion->product_id,
                'applied_by' => $owner->id, 'action_type' => 'promo_bundle', 'discount_percent' => 100,
                'original_price' => 50, 'promotional_price' => 0, 'status' => 'active', 'started_at' => now()]);
            $items[] = ['product_id' => $product->product_id, 'quantity' => 1];
        }
        $this->actingAs($owner)->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson(route('admin.pos.checkout'), ['items' => $items])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('sales_transactions', 0);
        $items[0]['quantity'] = 2;
        $this->postJson(route('admin.pos.checkout'), ['items' => $items])->assertCreated()->assertJsonPath('total', 200);
    }

    public function test_two_free_products_cannot_qualify_each_other_for_a_bundle(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $a = Product::create(['sku' => 'CYCLE-A', 'name' => 'A', 'current_stock' => 5, 'unit_price' => 100]);
        $b = Product::create(['sku' => 'CYCLE-B', 'name' => 'B', 'current_stock' => 5, 'unit_price' => 100]);
        foreach ([[$a, $b], [$b, $a]] as [$free, $companion]) {
            ProductPromotion::create(['product_id' => $free->product_id, 'bundle_product_id' => $companion->product_id,
                'applied_by' => $owner->id, 'action_type' => 'promo_bundle', 'discount_percent' => 100,
                'original_price' => 100, 'promotional_price' => 0, 'status' => 'active', 'started_at' => now()]);
        }
        $this->actingAs($owner)->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson(route('admin.pos.checkout'), ['items' => [
                ['product_id' => $a->product_id, 'quantity' => 1], ['product_id' => $b->product_id, 'quantity' => 1],
            ]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(5, $a->fresh()->current_stock);
    }

    public function test_product_totals_include_paid_sales_only(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'TOTALS', 'name' => 'Totals Part', 'current_stock' => 5]);
        foreach (['paid', 'pending'] as $status) {
            $sale = SalesTransaction::create(['staff_id' => $owner->id, 'payment_status' => $status,
                'sale_date' => now(), 'total_sale_amount' => 200]);
            SalesItem::create(['sale_id' => $sale->sale_id, 'product_id' => $product->product_id,
                'quantity' => 2, 'unit_sale_price' => 100, 'unit_cost' => 50, 'line_total' => 200]);
        }
        $row = $this->actingAs($owner)->get(route('admin.products'))->assertOk()->viewData('products')->first();
        $this->assertSame(2, (int) $row->units_sold);
        $this->assertSame(200.0, (float) $row->sales_revenue);
    }

    public function test_live_inventory_detects_metadata_changes_within_the_same_second(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'META', 'name' => 'Old Name', 'current_stock' => 5, 'shelf_location' => 'A']);
        $before = $this->actingAs($owner)->getJson(route('inventory.live'))->json('version');
        $product->update(['name' => 'New Name', 'shelf_location' => 'B']);
        $after = $this->getJson(route('inventory.live'))->assertOk()->assertJsonPath('products.0.name', 'New Name');
        $this->assertNotSame($before, $after->json('version'));
    }

    public function test_owner_can_open_a_historical_receipt_without_a_cashier(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $sale = SalesTransaction::create(['staff_id' => null, 'sale_date' => now(), 'total_sale_amount' => 100]);
        $this->actingAs($owner)->get(route('admin.pos.receipts.show', $sale))->assertOk();
    }
}
