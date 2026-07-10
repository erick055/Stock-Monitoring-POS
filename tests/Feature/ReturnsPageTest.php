<?php

namespace Tests\Feature;

use App\Models\CustomerReturn;
use App\Models\DamagedGood;
use App\Models\InventoryLedger;
use App\Models\Product;
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

        $response = $this->actingAs($admin)->post('/admin/returns/customer', [
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

    public function test_damage_log_removes_sellable_stock_and_creates_ledger_entry(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'DMG-FILTER',
            'name' => 'Damage Filter',
            'category' => 'Filters',
            'unit_price' => 150,
            'current_stock' => 4,
        ]);

        $response = $this->actingAs($staff)->post('/staff/returns/damage', [
            'product_id' => $product->product_id,
            'quantity' => 3,
            'damage_reason' => 'Water damaged',
            'replacement_status' => 'ordered',
            'status' => 'reported',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, $product->fresh()->current_stock);
        $this->assertSame(1, DamagedGood::count());
        $this->assertSame(1, InventoryLedger::where('reason_code', 'DAMAGED_GOODS')->count());
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
}
