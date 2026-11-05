<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\SellingSpeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellingSpeedClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_low_demand_battery_and_rotor_are_slow_and_velocities_match_analytics(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $sale = SalesTransaction::create(['staff_id' => $owner->id, 'payment_status' => 'paid', 'sale_date' => now(), 'total_sale_amount' => 1090]);
        foreach ([['BATTERY', '12V 7Ah battery', 1000, 3], ['ROTOR', 'Rotor', 597, 3], ['NORMAL', 'Normal part', 20, 3], ['FAST', 'Fast part', 5, 100]] as [$sku, $name, $stock, $sold]) {
            $product = Product::create(['sku' => $sku, 'name' => $name, 'current_stock' => $stock, 'unit_price' => 10]);
            SalesItem::create(['sale_id' => $sale->sale_id, 'product_id' => $product->product_id, 'quantity' => $sold, 'unit_sale_price' => 10, 'line_total' => $sold * 10]);
        }
        $cards = $this->actingAs($owner)->get(route('admin.low-stocks'))->assertOk()->viewData('fastMoving')->keyBy('sku');
        $this->assertSame('2334 days', $cards['BATTERY']['days_left']);
        $this->assertSame('Slow Moving', $cards['BATTERY']['status']);
        $this->assertSame('Slow Moving', $cards['ROTOR']['status']);
        $this->assertSame('Normal', $cards['NORMAL']['status']);
        $this->assertSame('Fast Moving', $cards['FAST']['status']);
        $ranking = $this->get(route('admin.analytics'))->assertOk()->viewData('sellingSpeedRanking')->keyBy('sku');
        foreach ($cards as $sku => $card) {
            $this->assertEquals($ranking[$sku]->units_per_day, $card['units_per_day']);
        }
    }

    public function test_stock_cover_threshold_boundaries(): void
    {
        $service = app(SellingSpeedService::class);
        $this->assertSame('Fast Moving', $service->classify(14));
        $this->assertSame('Normal', $service->classify(15));
        $this->assertSame('Normal', $service->classify(60));
        $this->assertSame('Slow Moving', $service->classify(61));
        $this->assertSame('Slow Moving', $service->classify(2334));
    }
}
