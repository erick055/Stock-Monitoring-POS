<?php

namespace Tests\Feature;

use App\Models\DeadStockMlModel;
use App\Models\DeadStockMlPrediction;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\DeadStockMachineLearning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeadStockMachineLearningTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_an_explicit_insufficient_history_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.dead-stock.train-model'))
            ->assertRedirect()
            ->assertSessionHasErrors('ml');

        $this->assertDatabaseCount('dead_stock_ml_models', 0);
    }

    public function test_staff_cannot_train_the_model(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)
            ->post(route('admin.dead-stock.train-model'))
            ->assertForbidden();
    }

    public function test_model_trains_from_historical_outcomes_and_saves_predictions(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $active = $this->oldProduct('ML-ACTIVE', 'Historically Active Part', 300, 8);
        $stagnant = $this->oldProduct('ML-IDLE', 'Historically Stagnant Part', 1200, 8);

        foreach (range(15, 1) as $monthsAgo) {
            $date = now()->subMonths($monthsAgo)->startOfMonth()->addDays(5);
            $sale = SalesTransaction::create([
                'staff_id' => $staff->id,
                'subtotal' => 500,
                'tax_amount' => 0,
                'labor_amount' => 0,
                'total_sale_amount' => 500,
                'payment_status' => 'paid',
                'payment_method' => 'cash',
                'sale_date' => $date,
            ]);
            SalesItem::create([
                'sale_id' => $sale->sale_id,
                'product_id' => $active->product_id,
                'quantity' => 2,
                'unit_sale_price' => 250,
                'unit_cost' => 150,
                'line_total' => 500,
            ]);
        }

        $result = app(DeadStockMachineLearning::class)->trainAndPredict();

        $this->assertInstanceOf(DeadStockMlModel::class, $result['model']);
        $this->assertGreaterThanOrEqual(20, $result['model']->training_samples);
        $this->assertSame(2, $result['predictions']);
        $this->assertDatabaseCount('dead_stock_ml_predictions', 2);
        $this->assertGreaterThan(
            DeadStockMlPrediction::where('product_id', $active->product_id)->value('stagnation_probability'),
            DeadStockMlPrediction::where('product_id', $stagnant->product_id)->value('stagnation_probability')
        );
    }

    private function oldProduct(string $sku, string $name, float $unitCost, int $stock): Product
    {
        $product = Product::create([
            'sku' => $sku,
            'name' => $name,
            'category' => 'ML Test',
            'unit_cost' => $unitCost,
            'unit_price' => $unitCost * 1.5,
            'current_stock' => $stock,
            'is_active' => true,
        ]);
        $product->forceFill(['created_at' => now()->subMonths(18), 'updated_at' => now()->subMonths(18)])->save();

        return $product;
    }
}
