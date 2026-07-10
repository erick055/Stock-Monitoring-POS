<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_products_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Product::create([
            'sku' => 'ENG-OIL-1L', 'name' => 'Engine Oil 1L', 'category' => 'Lubricants',
            'unit_cost' => 180, 'unit_price' => 250, 'current_stock' => 42, 'reorder_level' => 10,
        ]);

        $this->actingAs($admin)->get('/admin/products')
            ->assertOk()->assertSee('Products Inventory')->assertSee('Engine Oil 1L')
            ->assertSee('View only')->assertDontSee('Add Product');
    }

    public function test_staff_cannot_view_admin_products_page(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->get('/admin/products')->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/products')->assertRedirect(route('login'));
    }

    public function test_staff_can_view_the_same_read_only_product_catalog(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        Product::create([
            'sku' => 'BRK-PAD-01', 'name' => 'Brake Pad', 'category' => 'Brakes',
            'unit_cost' => 500, 'unit_price' => 750, 'current_stock' => 8, 'reorder_level' => 5,
        ]);

        $this->actingAs($staff)->get('/staff/products')
            ->assertOk()->assertSee('Brake Pad')->assertSee('View only')->assertDontSee('Add Product');
    }

    public function test_product_search_and_category_filter_work(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Product::create(['sku' => 'OIL-01', 'name' => 'Engine Oil', 'category' => 'Lubricants']);
        Product::create(['sku' => 'TIRE-01', 'name' => 'Front Tire', 'category' => 'Tires']);

        $this->actingAs($admin)->get('/admin/products?search=oil&category=Lubricants')
            ->assertOk()->assertSee('Engine Oil')->assertDontSee('Front Tire');
    }

    public function test_products_routes_do_not_accept_writes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($admin)->post('/admin/products', ['name' => 'Not allowed'])->assertMethodNotAllowed();
        $this->actingAs($staff)->post('/staff/products', ['name' => 'Not allowed'])->assertMethodNotAllowed();
        $this->assertDatabaseCount('products', 0);
    }
}
