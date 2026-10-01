<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\ProductDemandMachineLearning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductDemandMachineLearningTest extends TestCase
{
    use RefreshDatabase;

    public function test_sparse_history_does_not_invent_predictions(): void
    {
        Product::create(['sku' => 'NEW-DEMAND', 'name' => 'New Demand Part']);
        $result = app(ProductDemandMachineLearning::class)->current();
        $this->assertFalse($result['available']);
        $this->assertNull($result['items'][0]['predicted_units']);
        $this->assertNull($result['validation_mae']);
    }

    public function test_model_learns_local_sales_and_refreshes_after_a_paid_sale(): void
    {
        Http::preventStrayRequests();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-02 12:00:00'));
        $staff = User::factory()->create(['role' => 'staff']);
        $products = collect();
        foreach (range(1, 5) as $i) {
            $products->push(Product::create(['sku' => "ML-DEMAND-{$i}", 'name' => "Demand Part {$i}",
                'created_at' => now()->subDays(500), 'current_stock' => 100, 'unit_price' => 100]));
        }
        $products->each(fn ($product) => $product->forceFill(['created_at' => now()->subDays(500)])->save());
        foreach (range(1, 450, 10) as $days) {
            $sale = SalesTransaction::create(['staff_id' => $staff->id, 'subtotal' => 100, 'total_sale_amount' => 100,
                'payment_status' => 'paid', 'sale_date' => now()->subDays($days)]);
            foreach ($products as $i => $product) {
                SalesItem::create(['sale_id' => $sale->sale_id, 'product_id' => $product->product_id,
                    'quantity' => $i + 1, 'unit_sale_price' => 100, 'unit_cost' => 50, 'line_total' => 100 * ($i + 1)]);
            }
        }
        $service = app(ProductDemandMachineLearning::class);
        $result = $service->current();
        $this->assertTrue($result['available']);
        $this->assertGreaterThanOrEqual(20, $result['training_samples']);
        $this->assertGreaterThanOrEqual(5, $result['validation_samples']);
        $this->assertGreaterThanOrEqual(0, $result['validation_mae']);
        foreach ($result['items'] as $i => $item) {
            $this->assertEqualsWithDelta(3 * ($i + 1), $item['predicted_units'], 2);
        }
        $this->assertSame($result, $service->current());
        $newSale = SalesTransaction::create(['staff_id' => $staff->id, 'subtotal' => 100, 'total_sale_amount' => 100,
            'payment_status' => 'paid', 'sale_date' => now()]);
        SalesItem::create(['sale_id' => $newSale->sale_id, 'product_id' => $products->first()->product_id,
            'quantity' => 2, 'unit_sale_price' => 100, 'unit_cost' => 50, 'line_total' => 200]);
        $updated = $service->current();
        $this->assertSame($result['items'][0]['recent_units'] + 2, $updated['items'][0]['recent_units']);
        $newSale->update(['payment_status' => 'unpaid']);
        $this->assertSame($result['items'][0]['recent_units'], $service->current()['items'][0]['recent_units']);
        Http::assertNothingSent();
    }
}
