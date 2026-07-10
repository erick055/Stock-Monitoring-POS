<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeadStockPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_dead_stock_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/admin/deadstock');

        $response->assertOk();
        $response->assertSee('Dead Stock Detection');
    }

    public function test_dead_stock_page_shows_live_dead_and_slow_moving_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $deadProduct = Product::create([
            'sku' => 'OLD-BLOCK',
            'name' => 'Old Engine Block',
            'category' => 'Engine',
            'unit_cost' => 1000,
            'unit_price' => 1500,
            'current_stock' => 4,
        ]);
        $slowProduct = Product::create([
            'sku' => 'SLOW-EXH',
            'name' => 'Premium Exhaust',
            'category' => 'Exhaust',
            'unit_cost' => 3000,
            'unit_price' => 4200,
            'current_stock' => 8,
        ]);
        $healthyProduct = Product::create([
            'sku' => 'FAST-OIL',
            'name' => 'Fast Oil',
            'category' => 'Oils',
            'unit_cost' => 120,
            'unit_price' => 250,
            'current_stock' => 20,
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
            'product_id' => $slowProduct->product_id,
            'quantity' => 1,
            'unit_sale_price' => 4200,
            'unit_cost' => 3000,
            'line_total' => 4200,
        ]);
        SalesItem::create([
            'sale_id' => $sale->sale_id,
            'product_id' => $healthyProduct->product_id,
            'quantity' => 8,
            'unit_sale_price' => 250,
            'unit_cost' => 120,
            'line_total' => 2000,
        ]);

        $response = $this->actingAs($admin)->get('/admin/deadstock');

        $response->assertOk()
            ->assertSee('Old Engine Block')
            ->assertSee('Premium Exhaust')
            ->assertDontSee('Fast Oil')
            ->assertSee('₱4,000.00')
            ->assertSee('1 units / month')
            ->assertSee('AI Dead Stock Score')
            ->assertSee('Dead Stock')
            ->assertSee('No POS sales recorded in the last 90 days')
            ->assertSee('Apply clearance discount');
    }

    public function test_staff_cannot_view_dead_stock_page(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
        ]);

        $response = $this->actingAs($staff)->get('/admin/deadstock');

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_from_dead_stock_page(): void
    {
        $response = $this->get('/admin/deadstock');

        $response->assertRedirect('/');
    }
}
