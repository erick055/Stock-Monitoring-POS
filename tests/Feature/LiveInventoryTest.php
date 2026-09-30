<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_staff_can_read_the_live_inventory_snapshot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'LIVE-01', 'name' => 'Live Chain', 'category' => 'Drive',
            'shelf_location' => 'Shelf A', 'unit_price' => 450, 'current_stock' => 7,
        ]);

        foreach ([$admin, $staff] as $user) {
            $this->actingAs($user)->getJson(route('inventory.live'))
                ->assertOk()
                ->assertJsonStructure(['version', 'products', 'updated_at'])
                ->assertJsonPath('products.0.id', $product->product_id)
                ->assertJsonPath('products.0.stock', 7)
                ->assertJsonPath('products.0.shelfLocation', 'Shelf A');
        }
    }

    public function test_live_inventory_version_changes_with_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'LIVE-02', 'name' => 'Live Brake Pad', 'unit_price' => 300, 'current_stock' => 4,
        ]);

        $before = $this->actingAs($admin)->getJson(route('inventory.live'))->json('version');
        $product->update(['current_stock' => 2]);
        $after = $this->actingAs($admin)->getJson(route('inventory.live'));

        $after->assertOk()->assertJsonPath('products.0.stock', 2);
        $this->assertNotSame($before, $after->json('version'));
    }

    public function test_inventory_pages_include_live_synchronization_metadata(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($admin)->get(route('admin.inventory'))
            ->assertOk()->assertSee('data-live-inventory-page', false)->assertSee(route('inventory.live'));
        $this->actingAs($admin)->get(route('admin.products'))
            ->assertOk()->assertSee('data-live-inventory-page', false);
        $this->actingAs($staff)->get(route('staff.products'))
            ->assertOk()->assertSee('data-live-inventory-page', false);
        $this->actingAs($staff)->get(route('staff.pos'))
            ->assertOk()->assertSee('data-live-inventory-url', false)->assertSee('data-inventory-version', false);
    }
}
