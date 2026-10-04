<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeadStockMachineLearning;
use App\Services\ProductDemandMachineLearning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineLearningBackgroundTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_and_dead_stock_pages_do_not_train_models(): void
    {
        $this->partialMock(ProductDemandMachineLearning::class)->shouldNotReceive('refresh');
        $this->mock(DeadStockMachineLearning::class)->shouldNotReceive('trainAndPredict');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.analytics'))->assertOk();
        $this->get(route('admin.dead-stock'))->assertOk();
        $this->assertDatabaseCount('dead_stock_ml_models', 0);
    }

    public function test_command_refreshes_both_models_and_handles_insufficient_history(): void
    {
        $this->mock(ProductDemandMachineLearning::class)->shouldReceive('refresh')->once()
            ->andReturn(['available' => false, 'message' => 'Waiting for history']);
        $this->mock(DeadStockMachineLearning::class)->shouldReceive('trainAndPredict')->once()
            ->andThrow(new \RuntimeException('Insufficient history'));
        $this->artisan('ml:refresh')->assertSuccessful();
    }
}
