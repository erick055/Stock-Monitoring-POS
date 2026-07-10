<?php

namespace Tests\Feature;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_checkout_and_pos_updates_sales_and_stock(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'PAD-01',
            'name' => 'Brake Pad',
            'category' => 'Brakes',
            'unit_cost' => 100,
            'unit_price' => 250,
            'current_stock' => 5,
            'reorder_level' => 2,
        ]);

        $response = $this->actingAs($staff)->postJson('/staff/pos/checkout', [
            'items' => [
                ['product_id' => $product->product_id, 'quantity' => 2],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('total', 560);
        $this->assertSame(3, $product->fresh()->current_stock);
        $this->assertSame(1, SalesTransaction::count());
        $this->assertSame(1, SalesItem::count());
        $this->assertSame(1, InventoryLedger::where('reason_code', 'POS_SALE')->count());
    }

    public function test_pos_checkout_rejects_more_than_available_stock(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'TIRE-01',
            'name' => 'Front Tire',
            'category' => 'Tires',
            'unit_price' => 900,
            'current_stock' => 1,
        ]);

        $response = $this->actingAs($staff)->postJson('/staff/pos/checkout', [
            'items' => [
                ['product_id' => $product->product_id, 'quantity' => 2],
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertSame(1, $product->fresh()->current_stock);
        $this->assertSame(0, SalesTransaction::count());
    }
}
