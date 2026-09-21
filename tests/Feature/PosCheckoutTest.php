<?php

namespace Tests\Feature;

use App\Models\HeldOrder;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_groups_categories_without_case_sensitive_duplicates(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        foreach ([
            ['sku' => 'PAD-UPPER', 'name' => 'Front Pad', 'category' => 'Brakes'],
            ['sku' => 'PAD-LOWER', 'name' => 'Rear Pad', 'category' => 'brakes'],
            ['sku' => 'OIL-01', 'name' => 'Engine Oil', 'category' => 'Lubricants'],
        ] as $data) {
            Product::create([...$data, 'unit_price' => 100, 'current_stock' => 5]);
        }

        $response = $this->actingAs($staff)->get(route('staff.pos'));

        $response->assertOk()
            ->assertSee('data-category="brakes"', false)
            ->assertSee('data-category="lubricants"', false);

        $this->assertSame(1, substr_count($response->getContent(), 'data-category="brakes"'));
        $this->assertSame(2, $response->viewData('products')->where('categoryKey', 'brakes')->count());
    }

    public function test_staff_can_checkout_and_pos_updates_sales_and_stock(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'PAD-01',
            'name' => 'Brake Pad',
            'category' => 'Brakes',
            'unit_cost' => 100,
            'unit_price' => 250,
            'current_stock' => 5,
            'reorder_level' => 2,
        ]);

        $response = $this->actingAs($staff)->postJson('/staff/pos/checkout', [
            'items' => [
                ['product_id' => $product->product_id, 'quantity' => 2],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('total', 560)
            ->assertJsonPath('receipt.labor', 0)
            ->assertJsonPath('receipt.number', 'POS-000001')
            ->assertJsonPath('receipt.cashier', $staff->name)
            ->assertJsonPath('receipt.items.0.name', 'Brake Pad')
            ->assertJsonPath('receipt.items.0.quantity', 2);
        $this->assertSame(3, $product->fresh()->current_stock);
        $this->assertSame(1, SalesTransaction::count());
        $this->assertSame(1, SalesItem::count());
        $this->assertSame(1, InventoryLedger::where('reason_code', 'POS_SALE')->count());

        $sale = SalesTransaction::firstOrFail();
        $this->actingAs($staff)
            ->get(route('staff.pos.receipts.show', $sale))
            ->assertOk()
            ->assertSee('POS-000001')
            ->assertSee('Brake Pad')
            ->assertSee('Print receipt');

        $this->actingAs($staff)
            ->get(route('staff.pos'))
            ->assertOk()
            ->assertSee('Checkout Log')
            ->assertSee('POS-000001')
            ->assertSee('Optional labor charge');
    }

    public function test_optional_labor_is_saved_and_added_after_merchandise_tax(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'LABOR-01',
            'name' => 'Labor Test Part',
            'unit_cost' => 40,
            'unit_price' => 100,
            'current_stock' => 3,
        ]);

        $response = $this->actingAs($staff)->postJson(route('staff.pos.checkout'), [
            'labor_amount' => 250.50,
            'items' => [['product_id' => $product->product_id, 'quantity' => 1]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('receipt.subtotal', 100)
            ->assertJsonPath('receipt.tax', 12)
            ->assertJsonPath('receipt.labor', 250.5)
            ->assertJsonPath('receipt.total', 362.5);

        $sale = SalesTransaction::firstOrFail();
        $this->assertSame('250.50', $sale->labor_amount);
        $this->assertSame('362.50', $sale->total_sale_amount);

        $this->actingAs($staff)
            ->get(route('staff.pos.receipts.show', $sale))
            ->assertOk()
            ->assertSee('Labor')
            ->assertSee('P250.50');
    }

    public function test_staff_can_checkout_labor_only_without_buying_products(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)->postJson(route('staff.pos.checkout'), [
            'labor_amount' => 450,
        ]);

        $response->assertCreated()
            ->assertJsonPath('receipt.number', 'POS-000001')
            ->assertJsonPath('receipt.subtotal', 0)
            ->assertJsonPath('receipt.tax', 0)
            ->assertJsonPath('receipt.labor', 450)
            ->assertJsonPath('receipt.total', 450)
            ->assertJsonCount(0, 'receipt.items');

        $sale = SalesTransaction::firstOrFail();
        $this->assertSame('450.00', $sale->labor_amount);
        $this->assertSame('450.00', $sale->total_sale_amount);
        $this->assertDatabaseCount('sales_items', 0);
        $this->assertDatabaseCount('inventory_ledgers', 0);

        $this->actingAs($staff)->get(route('staff.pos.receipts.show', $sale))
            ->assertOk()
            ->assertSee('Labor / service only')
            ->assertSee('No products purchased')
            ->assertSee('P450.00');

        $this->actingAs($staff)->get(route('staff.pos'))
            ->assertOk()
            ->assertSee('POS-000001')
            ->assertSee('Labor only');
    }

    public function test_pos_rejects_checkout_without_products_or_labor(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)->postJson(route('staff.pos.checkout'), [
            'items' => [],
            'labor_amount' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('checkout');

        $this->assertDatabaseCount('sales_transactions', 0);
    }

    public function test_pos_rejects_a_negative_labor_charge(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'LABOR-NEG-01',
            'name' => 'Negative Labor Test Part',
            'unit_price' => 100,
            'current_stock' => 1,
        ]);

        $this->actingAs($staff)->postJson(route('staff.pos.checkout'), [
            'labor_amount' => -1,
            'items' => [['product_id' => $product->product_id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('labor_amount');

        $this->assertSame(0, SalesTransaction::count());
        $this->assertSame(1, $product->fresh()->current_stock);
    }

    public function test_pos_displays_and_charges_the_admin_approved_promotional_price(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'CLEARANCE-01', 'name' => 'Clearance Part', 'category' => 'Parts',
            'unit_cost' => 80, 'unit_price' => 200, 'current_stock' => 5,
        ]);
        ProductPromotion::create([
            'product_id' => $product->product_id,
            'applied_by' => $admin->id,
            'action_type' => 'clearance',
            'discount_percent' => 25,
            'original_price' => 200,
            'promotional_price' => 150,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->actingAs($staff)->get(route('staff.pos'))
            ->assertOk()
            ->assertViewHas('products', fn ($products) => $products->firstWhere('id', $product->product_id)['price'] === 150.0);

        $this->actingAs($staff)->postJson(route('staff.pos.checkout'), [
            'items' => [['product_id' => $product->product_id, 'quantity' => 2]],
        ])->assertCreated()
            ->assertJsonPath('receipt.subtotal', 300)
            ->assertJsonPath('receipt.items.0.unit_price', 150)
            ->assertJsonPath('total', 336);

        $this->assertSame('150.00', SalesItem::firstOrFail()->unit_sale_price);
    }

    public function test_pos_displays_and_charges_a_selected_promo_bundle_item_at_zero_value(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $freeProduct = Product::create([
            'sku' => 'FREE-DEAD-01', 'name' => 'Free Dead Stock Part',
            'unit_cost' => 40, 'unit_price' => 100, 'current_stock' => 3,
        ]);
        $companion = Product::create([
            'sku' => 'BUNDLE-MAIN-01', 'name' => 'Bundle Main Product',
            'unit_price' => 500, 'current_stock' => 5,
        ]);
        ProductPromotion::create([
            'product_id' => $freeProduct->product_id,
            'bundle_product_id' => $companion->product_id,
            'applied_by' => $admin->id,
            'action_type' => 'promo_bundle',
            'discount_percent' => 100,
            'original_price' => 100,
            'promotional_price' => 0,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->actingAs($staff)->get(route('staff.pos'))
            ->assertOk()
            ->assertViewHas('products', function ($products) use ($freeProduct, $companion) {
                $item = $products->firstWhere('id', $freeProduct->product_id);

                return $item['price'] === 0.0
                    && $item['promotion']['bundleProduct']['id'] === $companion->product_id;
            });

        $this->actingAs($staff)->postJson(route('staff.pos.checkout'), [
            'items' => [['product_id' => $freeProduct->product_id, 'quantity' => 1]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $response = $this->actingAs($staff)->postJson(route('staff.pos.checkout'), [
            'items' => [
                ['product_id' => $companion->product_id, 'quantity' => 1],
                ['product_id' => $freeProduct->product_id, 'quantity' => 1],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('receipt.subtotal', 500)
            ->assertJsonPath('receipt.items.0.name', 'Bundle Main Product')
            ->assertJsonPath('receipt.items.0.unit_price', 500)
            ->assertJsonPath('receipt.items.1.name', 'Free Dead Stock Part')
            ->assertJsonPath('receipt.items.1.unit_price', 0)
            ->assertJsonPath('receipt.total', 560);

        $this->assertSame(2, SalesItem::count());
    }

    public function test_staff_can_hold_resume_and_complete_an_order(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'HELD-PAD-01',
            'name' => 'Held Brake Pad',
            'category' => 'Brakes',
            'unit_cost' => 80,
            'unit_price' => 200,
            'current_stock' => 5,
        ]);

        $holdResponse = $this->actingAs($staff)->postJson(route('staff.pos.holds.store'), [
            'labor_amount' => 75,
            'items' => [['product_id' => $product->product_id, 'quantity' => 2]],
        ]);

        $holdResponse->assertCreated()
            ->assertJsonPath('hold.number', 'HOLD-000001')
            ->assertJsonPath('hold.labor', 75)
            ->assertJsonPath('hold.total', 523)
            ->assertJsonPath('hold.items.0.name', 'Held Brake Pad');

        $heldOrder = HeldOrder::firstOrFail();
        $this->assertSame(5, $product->fresh()->current_stock);
        $this->assertSame('held', $heldOrder->status);

        $this->actingAs($staff)
            ->get(route('staff.pos'))
            ->assertOk()
            ->assertSee('HOLD-000001')
            ->assertSee('Held Brake Pad');

        $checkoutResponse = $this->actingAs($staff)->postJson(route('staff.pos.checkout'), [
            'held_order_id' => $heldOrder->held_order_id,
            'labor_amount' => 75,
            'items' => [['product_id' => $product->product_id, 'quantity' => 2]],
        ]);

        $checkoutResponse->assertCreated()
            ->assertJsonPath('receipt.number', 'POS-000001')
            ->assertJsonPath('receipt.labor', 75)
            ->assertJsonPath('receipt.total', 523);
        $this->assertSame(3, $product->fresh()->current_stock);
        $this->assertSame('completed', $heldOrder->fresh()->status);
        $this->assertSame(SalesTransaction::firstOrFail()->sale_id, $heldOrder->fresh()->completed_sale_id);
    }

    public function test_staff_can_cancel_a_held_order_without_changing_stock(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'CANCEL-HOLD-01', 'name' => 'Cancel Hold Product',
            'unit_price' => 100, 'current_stock' => 4,
        ]);

        $this->actingAs($staff)->postJson(route('staff.pos.holds.store'), [
            'items' => [['product_id' => $product->product_id, 'quantity' => 1]],
        ])->assertCreated();

        $heldOrder = HeldOrder::firstOrFail();
        $this->actingAs($staff)
            ->deleteJson(route('staff.pos.holds.cancel', $heldOrder))
            ->assertOk();

        $this->assertSame('cancelled', $heldOrder->fresh()->status);
        $this->assertSame(4, $product->fresh()->current_stock);
    }

    public function test_pos_checkout_rejects_more_than_available_stock(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'TIRE-01',
            'name' => 'Front Tire',
            'category' => 'Tires',
            'unit_price' => 900,
            'current_stock' => 1,
        ]);

        $response = $this->actingAs($staff)->postJson('/staff/pos/checkout', [
            'items' => [
                ['product_id' => $product->product_id, 'quantity' => 2],
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertSame(1, $product->fresh()->current_stock);
        $this->assertSame(0, SalesTransaction::count());
    }

    public function test_guest_cannot_open_a_pos_receipt(): void
    {
        $sale = SalesTransaction::create([
            'subtotal' => 100,
            'tax_amount' => 12,
            'total_sale_amount' => 112,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'sale_date' => now(),
        ]);

        $this->get(route('staff.pos.receipts.show', $sale))->assertRedirect(route('login'));
    }
}
