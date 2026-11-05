<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\SellingSpeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class SalesVelocityTest extends TestCase
{
    use RefreshDatabase;

    private function sold(User $owner, Product $product, int $quantity, string $date, string $status = 'paid'): void
    {
        $sale = SalesTransaction::create(['staff_id' => $owner->id, 'payment_status' => $status,
            'sale_date' => $date, 'total_sale_amount' => $quantity * 10]);
        SalesItem::create(['sale_id' => $sale->sale_id, 'product_id' => $product->product_id,
            'quantity' => $quantity, 'unit_sale_price' => 10, 'unit_cost' => 5, 'line_total' => $quantity * 10]);
    }

    public function test_velocity_counts_zero_sales_days_and_only_paid_sales_in_the_calendar_window(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(12, 0));
        $owner = User::factory()->create(['role' => 'admin']);
        $bulk = Product::create(['sku' => 'BULK', 'name' => 'Bulk part', 'current_stock' => 5]);
        $frequent = Product::create(['sku' => 'FREQUENT', 'name' => 'Frequent part', 'current_stock' => 5]);
        $inactive = Product::create(['sku' => 'INACTIVE', 'name' => 'Inactive part', 'is_active' => false]);
        $this->sold($owner, $bulk, 90, '2026-10-06 00:00:00');
        $this->sold($owner, $bulk, 1000, '2026-10-05 23:59:59');
        $this->sold($owner, $bulk, 1000, '2026-11-04 12:00:01');
        $this->sold($owner, $bulk, 1000, '2026-11-01 12:00:00', 'cancelled');
        $this->sold($owner, $inactive, 1000, '2026-11-01 12:00:00');
        foreach (range(1, 3) as $number) $this->sold($owner, $frequent, 10, '2026-11-04 12:00:00');
        $rows = app(SellingSpeedService::class)->ranking();
        $this->assertSame(['BULK', 'FREQUENT'], $rows->pluck('sku')->all());
        $this->assertEquals(3, $rows[0]->units_per_day);
        $this->assertEquals(1, $rows[1]->units_per_day);
        $this->assertSame(1, (int) $rows[0]->purchase_frequency);
        $this->assertSame(3, (int) $rows[1]->purchase_frequency);
        $this->assertArrayNotHasKey('speed_score', $rows[0]->getAttributes());

        $export = $this->actingAs($owner)->get(route('admin.analytics.export'))->assertOk();
        $path = $export->baseResponse->getFile()->getPathname();
        $reader = new Reader;
        $speedRows = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== 'Selling Speed') continue;
                foreach ($sheet->getRowIterator() as $row) $speedRows[] = $row->toArray();
            }
        } finally { $reader->close(); unlink($path); }
        $this->assertSame('Sales velocity (units/day)', $speedRows[0][8]);
        $this->assertCount(9, $speedRows[0]);
        $this->assertEquals(3, $speedRows[1][8]);
        $this->assertEquals(1, $speedRows[2][8]);
    }

    public function test_equal_velocities_use_receipt_frequency_then_stable_product_id(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $one = Product::create(['sku' => 'ONE', 'name' => 'One']);
        $two = Product::create(['sku' => 'TWO', 'name' => 'Two']);
        $three = Product::create(['sku' => 'THREE', 'name' => 'Three']);
        $date = now()->toDateTimeString();
        $this->sold($owner, $one, 30, $date);
        foreach ([$two, $three] as $product) {
            $this->sold($owner, $product, 15, $date);
            $this->sold($owner, $product, 15, $date);
        }
        $this->assertSame(['TWO', 'THREE', 'ONE'], app(SellingSpeedService::class)->ranking()->pluck('sku')->all());
    }
}
