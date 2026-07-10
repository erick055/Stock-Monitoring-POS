<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LowStocksPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_low_stocks_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/admin/low-stocks');

        $response->assertOk();
        $response->assertSee('Stock Alerts and Monitoring');
    }

    public function test_low_stocks_page_shows_live_product_alerts_and_pos_demand(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $critical = Product::create([
            'sku' => 'OIL-LOW',
            'name' => 'Engine Oil Low',
            'category' => 'Oils',
            'unit_price' => 250,
            'current_stock' => 3,
            'reorder_level' => 5,
        ]);
        $warning = Product::create([
            'sku' => 'TIRE-WARN',
            'name' => 'Tire Warning',
            'category' => 'Tires',
            'unit_price' => 800,
            'current_stock' => 9,
            'reorder_level' => 5,
        ]);
        Product::create([
            'sku' => 'CHAIN-OK',
            'name' => 'Healthy Chain',
            'category' => 'Chains',
            'unit_price' => 600,
            'current_stock' => 40,
            'reorder_level' => 5,
        ]);
        $sale = SalesTransaction::create([
            'staff_id' => $staff->id,
            'subtotal' => 500,
            'tax_amount' => 60,
            'total_sale_amount' => 560,
            'payment_status' => 'paid',
            'sale_date' => now(),
        ]);
        SalesItem::create([
            'sale_id' => $sale->sale_id,
            'product_id' => $critical->product_id,
            'quantity' => 2,
            'unit_sale_price' => 250,
            'unit_cost' => 100,
            'line_total' => 500,
        ]);

        $response = $this->actingAs($admin)->get('/admin/low-stocks');

        $response->assertOk()
            ->assertSee('Engine Oil Low')
            ->assertSee('Tire Warning')
            ->assertDontSee('Healthy Chain')
            ->assertSee('2 units')
            ->assertSee('Critical')
            ->assertSee('Warning');
    }

    public function test_staff_cannot_view_low_stocks_page(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
        ]);

        $response = $this->actingAs($staff)->get('/admin/low-stocks');

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_from_low_stocks_page(): void
    {
        $response = $this->get('/admin/low-stocks');

        $response->assertRedirect('/');
    }
}
