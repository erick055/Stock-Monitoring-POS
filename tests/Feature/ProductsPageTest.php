<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_products_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'ENG-OIL-1L', 'name' => 'Engine Oil 1L', 'category' => 'Lubricants',
            'manufacturer' => 'MotoLube', 'manufacturer_part_number' => 'ML-ENG-1L',
            'description' => 'Fully synthetic motorcycle engine oil.', 'shelf_location' => 'Aisle A · Shelf 01',
            'unit_cost' => 180, 'unit_price' => 250, 'current_stock' => 42, 'reorder_level' => 10,
        ]);

        $this->actingAs($admin)->get('/admin/products')
            ->assertOk()->assertSee('Products Inventory')->assertSee('Engine Oil 1L')
            ->assertSee('MotoLube')->assertSee('ML-ENG-1L')
            ->assertSee('Fully synthetic motorcycle engine oil.')
            ->assertSee('Product identification')->assertSee('Pricing and inventory')
            ->assertSee('Recorded activity')->assertSee('Supplier Price Information')
            ->assertSee('AVERAGE PROFIT')->assertSee('₱70')->assertSee('Profit per Unit')
            ->assertDontSee('AVERAGE MARGIN')->assertDontSee('<th>Margin</th>', false)
            ->assertSee('popovertarget="product-details-'.$product->product_id.'"', false)
            ->assertSee('id="product-details-'.$product->product_id.'" popover', false)
            ->assertDontSee('data-product=', false)
            ->assertSee('View only')->assertDontSee('Add Product');
    }

    public function test_staff_cannot_view_admin_products_page(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->get('/admin/products')->assertForbidden();
    }

    public function test_product_details_show_dead_stock_status_using_paid_sales_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'STATUS-01', 'name' => 'Status Product', 'current_stock' => 5,
            'unit_cost' => 100, 'unit_price' => 150, 'reorder_level' => 5,
        ]);
        $empty = Product::create(['sku' => 'EMPTY-01', 'name' => 'Empty Product', 'current_stock' => 0]);
        $sale = SalesTransaction::create([
            'receipt_number' => 'STATUS-RECEIPT', 'user_id' => $admin->id,
            'sale_date' => now(), 'payment_status' => 'pending',
            'total_sale_amount' => 1500, 'payment_method' => 'cash',
        ]);
        SalesItem::create([
            'sale_id' => $sale->sale_id, 'product_id' => $product->product_id,
            'quantity' => 10, 'unit_sale_price' => 150, 'unit_cost' => 100, 'line_total' => 1500,
        ]);

        $response = $this->actingAs($admin)->get('/admin/products')->assertOk();
        $response->assertSee('Dead Stock Status');
        $this->assertSame('Dead Stock', $response->viewData('deadStockStatuses')[$product->product_id]);
        $this->assertSame('No stock to assess', $response->viewData('deadStockStatuses')[$empty->product_id]);

        $sale->update(['payment_status' => 'paid']);
        $response = $this->get('/admin/products')->assertOk();
        $this->assertSame('Healthy', $response->viewData('deadStockStatuses')[$product->product_id]);
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
            'shelf_location' => 'Aisle B · Bin 06',
            'unit_cost' => 500, 'unit_price' => 750, 'current_stock' => 8, 'reorder_level' => 5,
        ]);

        $this->actingAs($staff)->get('/staff/products')
            ->assertOk()->assertSee('Brake Pad')->assertSee('Aisle B · Bin 06')->assertSee('View only')
            ->assertDontSee('Add Product')->assertDontSee('Stock Management');
    }

    public function test_product_search_and_category_filter_work(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Product::create(['sku' => 'OIL-01', 'name' => 'Engine Oil', 'category' => 'Lubricants']);
        Product::create(['sku' => 'TIRE-01', 'name' => 'Front Tire', 'category' => 'Tires']);

        $this->actingAs($admin)->get('/admin/products?search=oil&category=Lubricants')
            ->assertOk()->assertSee('Engine Oil')->assertDontSee('Front Tire');
    }

    public function test_products_can_be_searched_by_shelf_location(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        Product::create(['sku' => 'LOC-01', 'name' => 'Located Product', 'shelf_location' => 'Rack Z-09']);
        Product::create(['sku' => 'LOC-02', 'name' => 'Other Product', 'shelf_location' => 'Rack A-01']);

        $this->actingAs($staff)->get('/staff/products?search=Z-09')
            ->assertOk()
            ->assertSee('Located Product')
            ->assertDontSee('Other Product');
    }

    public function test_products_can_be_sorted_from_the_catalog_toolbar(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Product::create(['sku' => 'SORT-LOW', 'name' => 'Low Stock Product', 'unit_price' => 100, 'current_stock' => 2]);
        Product::create(['sku' => 'SORT-HIGH', 'name' => 'High Stock Product', 'unit_price' => 500, 'current_stock' => 25]);

        $this->actingAs($admin)->get('/admin/products?sort=stock_high')
            ->assertOk()
            ->assertSee('Sort')
            ->assertSeeInOrder(['High Stock Product', 'Low Stock Product']);

        $this->actingAs($admin)->get('/admin/products?sort=name_desc')
            ->assertOk()
            ->assertSeeInOrder(['Low Stock Product', 'High Stock Product']);
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
