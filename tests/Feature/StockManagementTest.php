<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Product;
use App\Models\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_stock_management_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/admin/inventory')
            ->assertOk()
            ->assertSee('Stock Management')
            ->assertSee('Current Stock Level');
    }

    public function test_staff_cannot_view_admin_stock_management_page(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->get('/admin/inventory')->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/inventory')->assertRedirect(route('login'));
    }

    public function test_admin_can_add_a_product_with_an_opening_ledger_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.inventory.products.store'), [
            'sku' => 'ENG-OIL-1L',
            'name' => 'Engine Oil 1L',
            'category' => 'Lubricants',
            'unit_cost' => 180,
            'unit_price' => 250,
            'reorder_level' => 10,
            'qty_in' => 25,
            'reason_code' => 'OPENING_STOCK',
            'logs' => 'Opening inventory count.',
        ])->assertRedirect()->assertSessionHas('success');

        $product = Product::where('sku', 'ENG-OIL-1L')->firstOrFail();
        $this->assertSame(25, $product->current_stock);
        $this->assertDatabaseHas('inventory_ledgers', [
            'product_id' => $product->product_id,
            'qty_in' => 25,
            'qty_out' => 0,
            'reason_code' => 'OPENING_STOCK',
        ]);
    }

    public function test_stock_in_and_stock_out_update_balance_and_create_logs(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'BRK-PAD-01', 'name' => 'Brake Pad', 'unit_cost' => 500,
            'unit_price' => 750, 'current_stock' => 10, 'reorder_level' => 3,
        ]);

        $this->actingAs($staff)->post(route('staff.inventory.movements.store'), [
            'product_id' => $product->product_id,
            'movement_type' => 'in',
            'quantity' => 5,
            'reason_code' => 'PURCHASE_RECEIPT',
            'logs' => 'Supplier delivery.',
        ])->assertSessionHas('success');

        $this->actingAs($staff)->post(route('staff.inventory.movements.store'), [
            'product_id' => $product->product_id,
            'movement_type' => 'out',
            'quantity' => 4,
            'reason_code' => 'SALE',
            'logs' => 'POS sale.',
        ])->assertSessionHas('success');

        $this->assertSame(11, $product->fresh()->current_stock);
        $this->assertSame(2, InventoryLedger::where('product_id', $product->product_id)->count());
        $this->assertDatabaseHas('inventory_ledgers', ['qty_in' => 5, 'qty_out' => 0]);
        $this->assertDatabaseHas('inventory_ledgers', ['qty_in' => 0, 'qty_out' => 4]);
    }

    public function test_stock_cannot_be_adjusted_below_zero(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'FILTER-01', 'name' => 'Oil Filter', 'unit_cost' => 100,
            'unit_price' => 180, 'current_stock' => 2, 'reorder_level' => 2,
        ]);

        $this->actingAs($admin)->post(route('admin.inventory.movements.store'), [
            'product_id' => $product->product_id,
            'movement_type' => 'out',
            'quantity' => 3,
            'reason_code' => 'SALE',
        ])->assertRedirect()->assertSessionHasErrors('quantity');

        $this->assertSame(2, $product->fresh()->current_stock);
        $this->assertDatabaseCount('inventory_ledgers', 0);
    }
}
