<?php

namespace Tests\Feature;

use App\Models\CustomerReturn;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\SalesAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class RefundSalesAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_return_removes_refund_and_sale_margin_from_analytics(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'B1', 'name' => 'Returned part', 'unit_price' => 800, 'unit_cost' => 500, 'current_stock' => 5]);
        $sale = $this->actingAs($owner)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/admin/pos/checkout', ['payment_method' => 'cash', 'amount_tendered' => 10000000,
            'items' => [['product_id' => $product->product_id, 'quantity' => 1]], 'labor_amount' => 150,
        ])->assertCreated();
        $this->post('/admin/returns/customer', [
            'sale_id' => $sale->json('sale_id'), 'product_id' => $product->product_id,
            'quantity' => 1, 'reason' => 'Returned', 'item_condition' => 'sellable',
            'refund_amount' => 800, 'status' => 'approved',
        ])->assertSessionHasNoErrors();
        $analytics = $this->get('/admin/analytics')->assertOk()->assertViewHas('summary', function ($summary) {
            $this->assertEquals(150, $summary['total_sales']);
            $this->assertEquals(950, $summary['gross_sales']);
            $this->assertEquals(800, $summary['refunds']);
            $this->assertEquals(150, $summary['average_order_value']);
            $this->assertEquals(0, $summary['gross_profit']);

            return true;
        });
        $this->assertEquals(150, $analytics->viewData('dailySalesTotal'));
        $this->assertEquals(150, $analytics->viewData('weeklySales')->sum('total'));
        $dashboard = $this->get('/admin/dashboard')->assertOk()->assertSee('Refunds: ₱800.00')->viewData('dashboard');
        $this->assertEquals(150, $dashboard['sales_summary']['net_sales']);
        $this->assertEquals(150, collect($dashboard['chart'])->sum('value'));
        $record = $this->get('/admin/products')->assertOk()->assertSee('Net Sales Revenue')->viewData('products')->first();
        $this->assertEquals(0, $record->sales_revenue);
        $this->assertEquals(800, $record->gross_sales);
        $this->assertEquals(800, $record->refunds);
        $this->assertEquals(0, $record->sales_profit);

        $export = $this->get(route('admin.analytics.export'))->assertOk();
        $path = $export->baseResponse->getFile()->getPathname();
        $reader = new Reader;
        $sheets = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $sheets[$sheet->getName()][] = $row->toArray();
                }
            }
        } finally {
            $reader->close();
            unlink($path);
        }
        foreach (['Overview', 'Daily Summary'] as $sheet) {
            $rows = collect($sheets[$sheet])->mapWithKeys(fn ($row) => [$row[0] ?? '' => $row[1] ?? null]);
            $this->assertEquals(950, $rows['Gross sales']);
            $this->assertEquals(800, $rows['Refunds']);
            $this->assertEquals(150, $rows['Net sales']);
            $this->assertEquals(150, $rows['Average order value']);
            $this->assertEquals(0, $rows['Gross profit']);
        }
        $this->assertEquals(150, array_sum(array_column(array_slice($sheets['Period Sales'], 1), 4)));
        $this->assertEquals(800, $sheets['Approved Refunds'][1][5]);
    }

    public function test_refunds_and_margin_reversals_use_approval_day_and_original_cashier_and_prices(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 12:00:00'));
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'staff']);
        $product = Product::create(['sku' => 'PARTIAL', 'name' => 'Partial return', 'unit_price' => 800, 'unit_cost' => 500, 'current_stock' => 10]);
        $sale = $this->actingAs($staff)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/staff/pos/checkout', ['payment_method' => 'cash', 'amount_tendered' => 10000000,
            'items' => [['product_id' => $product->product_id, 'quantity' => 3]], 'labor_amount' => 150,
        ])->assertCreated();
        $this->travelTo(Carbon::parse('2026-11-02 12:00:00'));
        $this->withSession(['auth.last_activity_at' => now()->timestamp]);
        foreach ([800, 600] as $refund) {
            $this->post('/staff/returns/customer', [
                'sale_id' => $sale->json('sale_id'), 'product_id' => $product->product_id,
                'quantity' => 1, 'reason' => 'Return', 'item_condition' => 'sellable', 'refund_amount' => $refund,
            ])->assertSessionHasNoErrors();
        }
        $returns = CustomerReturn::orderBy('return_id')->get();
        $service = app(SalesAnalyticsService::class);
        $this->assertEquals(2550, $service->summary()['net_sales']); // Pending does not count.
        $this->assertNull($returns[0]->approved_at);
        $product->update(['unit_price' => 1000, 'unit_cost' => 900]);
        $this->travelTo(Carbon::parse('2026-11-03 00:00:00'));
        $this->withSession(['auth.last_activity_at' => now()->timestamp]);
        $this->actingAs($owner)->patch('/admin/returns/customer/'.$returns[0]->return_id.'/review', ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->patch('/admin/returns/customer/'.$returns[1]->return_id.'/review', ['decision' => 'rejected'])->assertSessionHasNoErrors();
        $this->assertEquals('2026-11-03 00:00:00', $returns[0]->fresh()->approved_at->format('Y-m-d H:i:s'));
        $all = $service->summary();
        $this->assertEquals(1750, $all['net_sales']);
        $this->assertEquals(600, $all['gross_profit']);
        $today = $service->summary(now()->startOfDay(), now()->endOfDay(), $staff->id);
        $this->assertEquals(-800, $today['net_sales']);
        $this->assertEquals(-300, $today['gross_profit']);
        $this->assertEquals(0, $today['average_order_value']);
        $this->assertEquals(0, $service->summary(staffId: $other->id)['net_sales']);
        $this->assertEquals(0, $service->summary(now()->subDay()->startOfDay(), now()->subDay()->endOfDay())['refunds']);
        $day = $this->get('/admin/analytics?period=day&range=2026-11-03')->assertOk()->assertSee('Refunds')->viewData('weeklySales')->first();
        $this->assertEquals(-800, $day['total']);
        $this->assertEquals(800, $day['refunds']);
        $this->assertEquals(0, $day['gross_sales']);
        $cashier = $this->actingAs($staff)->get('/staff/dashboard')->assertOk()->viewData('dashboard');
        $this->assertEquals(-800, $cashier['sales_summary']['net_sales']);
        $this->assertEquals(-800, collect($cashier['chart'])->last()['value']);
    }

    public function test_duplicate_receipt_lines_do_not_multiply_refunds_and_use_weighted_sale_margin(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'LINES', 'name' => 'Duplicate lines']);
        $sale = SalesTransaction::create(['staff_id' => $owner->id, 'sale_date' => now(), 'total_sale_amount' => 1601, 'payment_status' => 'paid']);
        foreach ([800, 801] as $price) {
            SalesItem::create(['sale_id' => $sale->sale_id, 'product_id' => $product->product_id, 'quantity' => 1, 'unit_sale_price' => $price, 'unit_cost' => 500, 'line_total' => $price]);
        }
        CustomerReturn::create(['sale_id' => $sale->sale_id, 'product_id' => $product->product_id, 'quantity' => 1, 'reason' => 'Return', 'refund_amount' => 800, 'status' => 'approved', 'returned_at' => now(), 'approved_at' => now()]);
        $summary = app(SalesAnalyticsService::class)->summary();
        $this->assertEquals(801, $summary['net_sales']);
        $this->assertEquals(300.5, $summary['gross_profit']);
        $batched = app(SalesAnalyticsService::class)->productSummaries([$product->product_id])[$product->product_id];
        $this->assertEquals(801, $batched['net_sales']);
        $this->assertEquals(300.5, $batched['gross_profit']);
        $sale->update(['payment_status' => 'cancelled']);
        $summary = app(SalesAnalyticsService::class)->summary();
        $this->assertEquals(0, $summary['net_sales']);
        $this->assertEquals(0, $summary['gross_profit']);
    }
}
