<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_analytics_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/admin/analytics');

        $response->assertOk();
        $response->assertSee('Sales & Analytics Dashboard');
    }

    public function test_analytics_uses_pos_sales_and_stock_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $oil = Product::create([
            'sku' => 'OIL-01',
            'name' => 'Engine Oil 1L',
            'category' => 'Oils',
            'unit_cost' => 120,
            'unit_price' => 250,
            'current_stock' => 80,
            'reorder_level' => 10,
        ]);
        Product::create([
            'sku' => 'BOLT-01',
            'name' => 'Small Bolt',
            'category' => 'Hardware',
            'unit_cost' => 2,
            'unit_price' => 5,
            'current_stock' => 2,
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
            'product_id' => $oil->product_id,
            'quantity' => 2,
            'unit_sale_price' => 250,
            'unit_cost' => 120,
            'line_total' => 500,
        ]);

        $response = $this->actingAs($admin)->get('/admin/analytics');

        $response->assertOk()
            ->assertSee('₱560.00')
            ->assertSee('Engine Oil 1L')
            ->assertSee('Small Bolt')
            ->assertSee('Sales Day by Day')
            ->assertSee('Most Requested Items');
    }

    public function test_staff_cannot_view_analytics_page(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
        ]);

        $response = $this->actingAs($staff)->get('/admin/analytics');

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_from_analytics_page(): void
    {
        $response = $this->get('/admin/analytics');

        $response->assertRedirect('/');
    }
}
