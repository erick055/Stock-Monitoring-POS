<?php

namespace Tests\Feature;

use App\Models\CustomerReturn;
use App\Models\DamagedGood;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_damage_is_hidden_until_owner_accepts_and_rejection_releases_quantity(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create(['sku' => 'DAMAGE-QUEUE', 'name' => 'Damage Queue Part', 'unit_price' => 100, 'current_stock' => 5]);
        $sale = $this->createSale($staff, $product, 2, 100);
        $payload = ['sale_id' => $sale->sale_id, 'product_id' => $product->product_id, 'quantity' => 1,
            'damage_reason' => 'Broken', 'replacement_status' => 'pending', 'status' => 'reviewed'];
        $this->actingAs($staff)->post(route('staff.returns.damage.store'), $payload)->assertSessionHasNoErrors();
        $damage = DamagedGood::firstOrFail();
        $this->assertSame('pending', $damage->status);
        $this->get(route('staff.returns'))->assertViewHas('damageLogs', fn ($rows) => $rows->isEmpty())
            ->assertViewHas('pendingDamages', fn ($rows) => $rows->isEmpty());
        $url = route('admin.returns.damage.review', $damage);
        $this->patch($url, ['decision' => 'accepted'])->assertForbidden();
        $this->actingAs($admin)->get(route('admin.returns'))->assertViewHas('pendingDamages', fn ($rows) => $rows->count() === 1);
        $this->patch($url, ['decision' => 'accepted'])->assertSessionHasNoErrors();
        $this->assertSame('reviewed', $damage->fresh()->status);
        $this->get(route('admin.returns'))->assertViewHas('damageLogs', fn ($rows) => $rows->count() === 1);
        $this->patch($url, ['decision' => 'rejected'])->assertSessionHasErrors('decision');
        $this->actingAs($staff)->post(route('staff.returns.damage.store'), $payload)->assertSessionHasNoErrors();
        $second = DamagedGood::latest('damage_id')->first();
        $this->actingAs($admin)->patch(route('admin.returns.damage.review', $second), ['decision' => 'rejected'])->assertSessionHasNoErrors();
        $this->get(route('admin.returns'))->assertViewHas('damageLogs', fn ($rows) => $rows->count() === 1)
            ->assertViewHas('receipts', fn ($rows) => $rows->first()['items']->first()['available_quantity'] === 1);
        $this->assertSame(5, $product->fresh()->current_stock);
    }

    public function test_staff_returns_require_owner_review_even_with_forged_approval(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = User::factory()->create(['role' => 'staff']);
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create(['sku' => 'REVIEW-RET', 'name' => 'Review Return', 'unit_price' => 100, 'current_stock' => 5]);
        $sale = $this->createSale($cashier, $product, 2, 100);
        $this->actingAs($staff)->post(route('staff.returns.customer.store'), [
            'sale_id' => $sale->sale_id, 'product_id' => $product->product_id, 'quantity' => 1,
            'reason' => 'Customer exchange', 'item_condition' => 'sellable', 'refund_amount' => 100, 'status' => 'approved',
        ])->assertSessionHasNoErrors();
        $return = CustomerReturn::firstOrFail();
        $this->assertSame('pending', $return->status);
        $this->assertSame(5, $product->fresh()->current_stock);
        $this->assertSame(0, InventoryLedger::where('reason_code', 'CUSTOMER_RETURN')->count());
        $url = route('admin.returns.customer.review', $return);
        $this->patch($url, ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($admin)->get(route('admin.returns'))->assertOk()->assertSee($url);
        $this->patch($url, ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $return->fresh()->status);
        $this->assertSame(6, $product->fresh()->current_stock);
        $this->assertDatabaseHas('inventory_ledgers', ['user_id' => $admin->id, 'reason_code' => 'CUSTOMER_RETURN']);
        $this->patch($url, ['decision' => 'approved'])->assertSessionHasErrors('decision');
        $this->assertSame(6, $product->fresh()->current_stock);
        $this->assertSame(1, InventoryLedger::where('reason_code', 'CUSTOMER_RETURN')->count());
    }

    public function test_owner_rejection_releases_reserved_quantity_without_restoring_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'REJECT-RET', 'name' => 'Reject Return', 'unit_price' => 100, 'current_stock' => 5]);
        $sale = $this->createSale($admin, $product, 1, 100);
        $return = CustomerReturn::create(['sale_id' => $sale->sale_id, 'product_id' => $product->product_id,
            'user_id' => $admin->id, 'quantity' => 1, 'reason' => 'Test', 'item_condition' => 'sellable',
            'refund_amount' => 100, 'status' => 'pending', 'returned_at' => now()]);
        $url = route('admin.returns.customer.review', $return);
        $this->actingAs($admin)->patch($url, ['decision' => 'rejected'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $return->fresh()->status);
        $this->assertSame(5, $product->fresh()->current_stock);
        $this->get(route('admin.returns'))->assertViewHas('receipts', fn ($rows) => $rows->first()['items']->first()['available_quantity'] === 1);
        $this->patch($url, ['decision' => 'approved'])->assertSessionHasErrors('decision');
        $this->assertSame(5, $product->fresh()->current_stock);
    }

    public function test_owner_approval_of_damaged_return_does_not_restore_sellable_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'DAMAGED-REVIEW', 'name' => 'Damaged Return', 'unit_price' => 100, 'current_stock' => 5]);
        $sale = $this->createSale($admin, $product, 1, 100);
        $return = CustomerReturn::create(['sale_id' => $sale->sale_id, 'product_id' => $product->product_id,
            'user_id' => $admin->id, 'quantity' => 1, 'reason' => 'Broken', 'item_condition' => 'damaged',
            'refund_amount' => 100, 'status' => 'pending', 'returned_at' => now()]);
        $this->actingAs($admin)->patch(route('admin.returns.customer.review', $return), ['decision' => 'approved'])
            ->assertSessionHasNoErrors();
        $this->assertSame('approved', $return->fresh()->status);
        $this->assertSame(5, $product->fresh()->current_stock);
        $this->assertSame(0, InventoryLedger::where('reason_code', 'CUSTOMER_RETURN')->count());
    }

    public function test_admin_can_view_returns_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/admin/returns');

        $response->assertOk();
        $response->assertSee('Return & Damage Management');
        $response->assertDontSee('Sale ID optional');
    }

    public function test_staff_can_view_returns_page(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)->get('/staff/returns');

        $response->assertOk();
        $response->assertSee('Record Product Return');
    }

    public function test_receipt_items_include_their_refundable_unit_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'VALUE-ITEM', 'name' => 'Valued Receipt Item', 'category' => 'Parts', 'unit_price' => 175.50, 'current_stock' => 2,
        ]);
        $this->createSale($admin, $product, 2, 175.50);

        $response = $this->actingAs($admin)->get('/admin/returns');

        $response->assertOk();
        $response->assertSee('Valued Receipt Item');
        $response->assertSee('"unit_price":175.5', false);
        $response->assertSee('"available_value":351', false);
    }

    public function test_approved_sellable_return_adds_stock_and_ledger_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'RET-OIL',
            'name' => 'Return Oil',
            'category' => 'Oils',
            'unit_price' => 250,
            'current_stock' => 5,
        ]);
        $sale = $this->createSale($admin, $product, 2, 250);

        $response = $this->actingAs($admin)->post('/admin/returns/customer', [
            'sale_id' => $sale->sale_id,
            'product_id' => $product->product_id,
            'quantity' => 2,
            'reason' => 'Customer exchange',
            'item_condition' => 'sellable',
            'refund_amount' => 500,
            'status' => 'approved',
        ]);

        $response->assertRedirect();
        $this->assertSame(7, $product->fresh()->current_stock);
        $this->assertSame(1, CustomerReturn::count());
        $this->assertSame(1, InventoryLedger::where('reason_code', 'CUSTOMER_RETURN')->count());
    }

    public function test_receipt_damage_is_documented_without_deducting_sold_stock_again(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'DMG-FILTER',
            'name' => 'Damage Filter',
            'category' => 'Filters',
            'unit_price' => 150,
            'current_stock' => 4,
        ]);
        $sale = $this->createSale($staff, $product, 3, 150);

        $response = $this->actingAs($staff)->post('/staff/returns/damage', [
            'sale_id' => $sale->sale_id,
            'product_id' => $product->product_id,
            'quantity' => 3,
            'damage_reason' => 'Water damaged',
            'replacement_status' => 'ordered',
            'status' => 'reported',
        ]);

        $response->assertRedirect();
        $this->assertSame(4, $product->fresh()->current_stock);
        $this->assertSame(1, DamagedGood::count());
        $this->assertSame($sale->sale_id, DamagedGood::first()->sale_id);
        $this->assertSame(0, InventoryLedger::where('reason_code', 'DAMAGED_GOODS')->count());
    }

    public function test_selected_product_must_belong_to_selected_receipt(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $soldProduct = Product::create([
            'sku' => 'SOLD-ITEM', 'name' => 'Sold Item', 'category' => 'Parts', 'unit_price' => 100, 'current_stock' => 2,
        ]);
        $otherProduct = Product::create([
            'sku' => 'OTHER-ITEM', 'name' => 'Other Item', 'category' => 'Parts', 'unit_price' => 100, 'current_stock' => 2,
        ]);
        $sale = $this->createSale($admin, $soldProduct, 1, 100);

        $response = $this->actingAs($admin)->post('/admin/returns/customer', [
            'sale_id' => $sale->sale_id,
            'product_id' => $otherProduct->product_id,
            'quantity' => 1,
            'reason' => 'Wrong item',
            'item_condition' => 'sellable',
            'refund_amount' => 100,
            'status' => 'approved',
        ]);

        $response->assertSessionHasErrors('product_id');
        $this->assertSame(0, CustomerReturn::count());
        $this->assertSame(2, $otherProduct->fresh()->current_stock);
    }

    public function test_return_quantity_cannot_exceed_remaining_receipt_quantity(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'LIMITED', 'name' => 'Limited Item', 'category' => 'Parts', 'unit_price' => 100, 'current_stock' => 2,
        ]);
        $sale = $this->createSale($admin, $product, 2, 100);

        $response = $this->actingAs($admin)->post('/admin/returns/customer', [
            'sale_id' => $sale->sale_id,
            'product_id' => $product->product_id,
            'quantity' => 3,
            'reason' => 'Too many',
            'item_condition' => 'sellable',
            'refund_amount' => 200,
            'status' => 'approved',
        ]);

        $response->assertSessionHasErrors('quantity');
        $this->assertSame(0, CustomerReturn::count());
    }

    public function test_refund_cannot_exceed_selected_receipt_item_value(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'REFUND-CAP', 'name' => 'Refund Cap', 'category' => 'Parts', 'unit_price' => 120, 'current_stock' => 2,
        ]);
        $sale = $this->createSale($admin, $product, 1, 120);

        $response = $this->actingAs($admin)->post('/admin/returns/customer', [
            'sale_id' => $sale->sale_id,
            'product_id' => $product->product_id,
            'quantity' => 1,
            'reason' => 'Refund test',
            'item_condition' => 'sellable',
            'refund_amount' => 121,
            'status' => 'approved',
        ]);

        $response->assertSessionHasErrors('refund_amount');
        $this->assertSame(0, CustomerReturn::count());
    }

    public function test_staff_cannot_view_returns_page(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
        ]);

        $response = $this->actingAs($staff)->get('/admin/returns');

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_from_returns_page(): void
    {
        $response = $this->get('/admin/returns');

        $response->assertRedirect('/');
    }

    private function createSale(User $staff, Product $product, int $quantity, float $unitPrice): SalesTransaction
    {
        $sale = SalesTransaction::create([
            'staff_id' => $staff->id,
            'subtotal' => $quantity * $unitPrice,
            'tax_amount' => 0,
            'total_sale_amount' => $quantity * $unitPrice,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'sale_date' => now(),
        ]);
        SalesItem::create([
            'sale_id' => $sale->sale_id,
            'product_id' => $product->product_id,
            'quantity' => $quantity,
            'unit_sale_price' => $unitPrice,
            'unit_cost' => 0,
            'line_total' => $quantity * $unitPrice,
        ]);

        return $sale;
    }
}
