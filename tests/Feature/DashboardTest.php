<?php

namespace Tests\Feature;

use App\Models\HeldOrder;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_uses_live_company_inventory_and_sales_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin Owner']);
        $staff = User::factory()->create(['role' => 'staff', 'name' => 'Sales Staff']);
        $lowProduct = Product::create([
            'sku' => 'LIVE-LOW', 'name' => 'Live Low Product', 'category' => 'Parts',
            'unit_price' => 500, 'current_stock' => 2, 'reorder_level' => 5,
        ]);
        Product::create([
            'sku' => 'LIVE-OK', 'name' => 'Live Healthy Product', 'category' => 'Oils',
            'unit_price' => 200, 'current_stock' => 20, 'reorder_level' => 5,
        ]);
        $sale = SalesTransaction::create([
            'staff_id' => $staff->id, 'subtotal' => 1000, 'tax_amount' => 120,
            'total_sale_amount' => 1120, 'payment_status' => 'paid', 'sale_date' => now(),
        ]);
        SalesItem::create([
            'sale_id' => $sale->sale_id, 'product_id' => $lowProduct->product_id,
            'quantity' => 2, 'unit_sale_price' => 500, 'line_total' => 1000,
        ]);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('TOTAL PRODUCTS')
            ->assertSee('TOTAL STOCK')
            ->assertSee('LOW STOCK ITEMS')
            ->assertSee('₱1,120.00')
            ->assertSee('Live Low Product')
            ->assertSee('Sales Staff')
            ->assertSee('Sales — Last 7 Days')
            ->assertDontSee('156')
            ->assertDontSee('₱45,320');
    }

    public function test_staff_dashboard_is_scoped_to_the_logged_in_staff_member(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'name' => 'Current Cashier']);
        $otherStaff = User::factory()->create(['role' => 'staff', 'name' => 'Other Cashier']);
        $product = Product::create([
            'sku' => 'STAFF-LIVE', 'name' => 'Staff Live Product',
            'unit_price' => 500, 'current_stock' => 2, 'reorder_level' => 5,
        ]);
        $ownSale = SalesTransaction::create([
            'staff_id' => $staff->id, 'subtotal' => 1000, 'tax_amount' => 120,
            'total_sale_amount' => 1120, 'payment_status' => 'paid', 'sale_date' => now(),
        ]);
        SalesItem::create([
            'sale_id' => $ownSale->sale_id, 'product_id' => $product->product_id,
            'quantity' => 2, 'unit_sale_price' => 500, 'line_total' => 1000,
        ]);
        SalesTransaction::create([
            'staff_id' => $otherStaff->id, 'subtotal' => 5000, 'tax_amount' => 600,
            'total_sale_amount' => 5600, 'payment_status' => 'paid', 'sale_date' => now(),
        ]);
        HeldOrder::create([
            'staff_id' => $staff->id, 'status' => 'held', 'held_at' => now(),
        ]);
        InventoryLedger::create([
            'product_id' => $product->product_id, 'user_id' => $staff->id,
            'qty_in' => 3, 'qty_out' => 0, 'reason_code' => 'PURCHASE_RECEIPT',
        ]);

        $this->actingAs($staff)->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('MY SALES TODAY')
            ->assertSee('₱1,120.00')
            ->assertSee('ITEMS SOLD')
            ->assertSee('MY HELD ORDERS')
            ->assertSee('1 held order(s) waiting')
            ->assertSee('Staff Live Product')
            ->assertSee('My Sales — Last 7 Days')
            ->assertDontSee('Stock Management')
            ->assertDontSee('₱5,600.00')
            ->assertDontSee('Other Cashier');
    }

    public function test_dashboard_requires_the_correct_account_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($admin)->get(route('staff.dashboard'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.dashboard'))->assertForbidden();
        auth()->logout();
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }
}
