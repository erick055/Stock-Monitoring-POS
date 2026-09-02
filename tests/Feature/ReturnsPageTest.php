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
