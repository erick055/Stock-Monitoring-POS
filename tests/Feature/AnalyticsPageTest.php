<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_analytics_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/admin/analytics');

        $response->assertOk();
        $response->assertSee('Sales & Analytics Dashboard')
            ->assertSee('Export analytics data')
            ->assertSee('Excel workbook')
            ->assertDontSee('PDF report')
            ->assertDontSee('CSV data');
    }

    public function test_analytics_uses_pos_sales_and_stock_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $oil = Product::create([
            'sku' => 'OIL-01',
            'name' => 'Engine Oil 1L',
            'category' => 'Oils',
            'unit_cost' => 120,
            'unit_price' => 250,
            'current_stock' => 80,
            'reorder_level' => 10,
        ]);
        Product::create([
            'sku' => 'BOLT-01',
            'name' => 'Small Bolt',
            'category' => 'Hardware',
            'unit_cost' => 2,
            'unit_price' => 5,
            'current_stock' => 2,
            'reorder_level' => 5,
        ]);
        $sale = SalesTransaction::create([
            'staff_id' => $staff->id,
            'subtotal' => 500,
            'tax_amount' => 60,
            'total_sale_amount' => 560,
            'payment_status' => 'paid',
            'sale_date' => now(),
        ]);
        SalesItem::create([
            'sale_id' => $sale->sale_id,
            'product_id' => $oil->product_id,
            'quantity' => 2,
            'unit_sale_price' => 250,
            'unit_cost' => 120,
            'line_total' => 500,
        ]);

        $response = $this->actingAs($admin)->get('/admin/analytics');

        $response->assertOk()
            ->assertSee('₱560.00')
            ->assertSee('GROSS PROFIT')
            ->assertSee('₱260')
            ->assertDontSee('PROFIT MARGIN')
            ->assertSee('Engine Oil 1L')
            ->assertSee('Small Bolt')
            ->assertSee('Sales Day by Day')
            ->assertSee('Interactive weekly bar chart of paid sales')
            ->assertSee('data-day-bar', false)
            ->assertSee('Weekly view')
            ->assertSee('Monthly view')
            ->assertSee('Yearly view')
            ->assertSee('Most Requested Items');
    }

    public function test_staff_cannot_view_analytics_page(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
        ]);

        $response = $this->actingAs($staff)->get('/admin/analytics');

        $response->assertForbidden();
    }

    public function test_admin_can_generate_a_strictly_ai_future_demand_forecast_with_groq(): void
    {
        Cache::forget('groq-demand-forecast-v1');
        config([
            'groq.api_key' => 'test-groq-key',
            'groq.model' => 'openai/gpt-oss-20b',
            'groq.base_url' => 'https://api.groq.com/openai/v1',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'AI-DEMAND-01', 'name' => 'AI Demand Chain', 'category' => 'Drive',
            'unit_price' => 500, 'current_stock' => 12, 'reorder_level' => 4,
        ]);
        $sale = SalesTransaction::create([
            'staff_id' => $staff->id, 'subtotal' => 1000, 'tax_amount' => 0,
            'total_sale_amount' => 1000, 'payment_status' => 'paid', 'sale_date' => now()->subDays(3),
        ]);
        SalesItem::create([
            'sale_id' => $sale->sale_id, 'product_id' => $product->product_id, 'quantity' => 2,
            'unit_sale_price' => 500, 'unit_cost' => 300, 'line_total' => 1000,
        ]);

        Http::fake(['api.groq.com/*' => Http::response([
            'id' => 'groq-test-response',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [['message' => ['content' => json_encode([
                'summary' => 'Demand is expected to remain steady over the next month.',
                'forecasts' => [[
                    'product_id' => $product->product_id,
                    'predicted_units' => 9,
                    'trend' => 'steady',
                    'confidence' => 'medium',
                    'rationale' => 'Recent weekly purchases show modest, recurring demand.',
                ]],
            ])]]],
        ])]);

        $this->actingAs($admin)->post(route('admin.analytics.demand-forecast'))
            ->assertRedirect()->assertSessionHas('success');

        $this->actingAs($admin)->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Future Product Demand')
            ->assertSee('<strong>9</strong> predicted units', false)
            ->assertSee('Medium confidence')
            ->assertSee('Predictions are estimates, not recorded demand');

        Http::assertSent(function (Request $request) use ($product) {
            $products = $request->data()['messages'][1]['content'] ?? '';

            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-groq-key')
                && data_get($request->data(), 'response_format.json_schema.strict') === true
                && str_contains($products, 'AI Demand Chain')
                && str_contains($products, (string) $product->product_id);
        });
    }

    public function test_analytics_does_not_call_groq_until_admin_requests_a_forecast(): void
    {
        Cache::forget('groq-demand-forecast-v1');
        config(['groq.api_key' => 'test-groq-key']);
        Http::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Generate AI forecast')
            ->assertSee('No AI forecast has been generated yet');

        Http::assertNothingSent();
    }

    public function test_sales_chart_supports_weekly_monthly_and_yearly_views(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/admin/analytics?period=week')
            ->assertOk()
            ->assertSee('WEEKLY VIEW')
            ->assertSee('Sales Day by Day')
            ->assertSee('Specific week');

        $monthly = $this->actingAs($admin)->get('/admin/analytics?period=month')
            ->assertOk()
            ->assertSee('MONTHLY VIEW')
            ->assertSee('Sales Day by Day')
            ->assertSee('Specific month');
        $this->assertSame(now()->daysInMonth, substr_count($monthly->getContent(), 'data-day-bar'));

        $yearly = $this->actingAs($admin)->get('/admin/analytics?period=year')
            ->assertOk()
            ->assertSee('YEARLY VIEW')
            ->assertSee('Sales Month by Month')
            ->assertSee('Specific year');
        $this->assertSame(12, substr_count($yearly->getContent(), 'data-day-bar'));
    }

    public function test_sales_chart_can_open_a_specific_week_month_and_year(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);

        foreach ([
            ['date' => '2025-01-07 10:00:00', 'total' => 110],
            ['date' => '2025-01-14 10:00:00', 'total' => 220],
            ['date' => '2025-02-03 10:00:00', 'total' => 330],
        ] as $record) {
            SalesTransaction::create([
                'staff_id' => $staff->id,
                'subtotal' => $record['total'],
                'tax_amount' => 0,
                'total_sale_amount' => $record['total'],
                'payment_status' => 'paid',
                'sale_date' => $record['date'],
            ]);
        }

        $week = $this->actingAs($admin)->get('/admin/analytics?period=week&range=2025-01-06');
        $week->assertOk()->assertSee('JAN 06, 2025 – JAN 12, 2025')->assertSee('Jan 06 – Jan 12, 2025');
        $this->assertCount(7, $week->viewData('weeklySales'));
        $this->assertSame(110.0, (float) $week->viewData('weeklySales')->sum('total'));

        $month = $this->actingAs($admin)->get('/admin/analytics?period=month&range=2025-01');
        $month->assertOk()->assertSee('JANUARY 2025')->assertSee('February 2025');
        $this->assertCount(31, $month->viewData('weeklySales'));
        $this->assertSame(330.0, (float) $month->viewData('weeklySales')->sum('total'));

        $year = $this->actingAs($admin)->get('/admin/analytics?period=year&range=2025');
        $year->assertOk()->assertSee('YEARLY VIEW · 2025');
        $this->assertCount(12, $year->viewData('weeklySales'));
        $this->assertSame(660.0, (float) $year->viewData('weeklySales')->sum('total'));
    }

    public function test_admin_can_export_whole_analytics_as_excel(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $xlsx = $this->actingAs($admin)->get(route('admin.analytics.export', ['period' => 'month']))
            ->assertOk()
            ->assertDownload();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx->headers->get('content-type'));
        $xlsxPath = $xlsx->baseResponse->getFile()->getPathname();
        $archive = new \ZipArchive;
        $this->assertTrue($archive->open($xlsxPath));
        $workbook = $archive->getFromName('xl/workbook.xml');
        $archive->close();
        $this->assertStringContainsString('Selling Speed', $workbook);
        $this->assertStringContainsString('Inventory', $workbook);

        $this->actingAs($admin)->get('/admin/analytics/export/pdf')->assertNotFound();
        $this->actingAs($admin)->get('/admin/analytics/export/csv')->assertNotFound();
    }

    public function test_staff_cannot_export_admin_analytics(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)
            ->get(route('admin.analytics.export'))
            ->assertForbidden();
    }

    public function test_items_are_ranked_by_paid_purchase_frequency_and_quantity(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $frequent = Product::create([
            'sku' => 'FREQ-01', 'name' => 'Frequent Filter', 'unit_price' => 100,
            'current_stock' => 20, 'is_active' => true,
        ]);
        $bulk = Product::create([
            'sku' => 'BULK-01', 'name' => 'Bulk Chain', 'unit_price' => 50,
            'current_stock' => 20, 'is_active' => true,
        ]);

        foreach (range(1, 3) as $day) {
            $sale = SalesTransaction::create([
                'staff_id' => $staff->id,
                'subtotal' => 100,
                'tax_amount' => 0,
                'total_sale_amount' => 100,
                'payment_status' => 'paid',
                'sale_date' => now()->subDays($day),
            ]);
            SalesItem::create([
                'sale_id' => $sale->sale_id,
                'product_id' => $frequent->product_id,
                'quantity' => 1,
                'unit_sale_price' => 100,
                'unit_cost' => 60,
                'line_total' => 100,
            ]);
        }

        $bulkSale = SalesTransaction::create([
            'staff_id' => $staff->id,
            'subtotal' => 500,
            'tax_amount' => 0,
            'total_sale_amount' => 500,
            'payment_status' => 'paid',
            'sale_date' => now(),
        ]);
        SalesItem::create([
            'sale_id' => $bulkSale->sale_id,
            'product_id' => $bulk->product_id,
            'quantity' => 10,
            'unit_sale_price' => 50,
            'unit_cost' => 30,
            'line_total' => 500,
        ]);

        $this->actingAs($admin)->get('/admin/analytics')
            ->assertOk()
            ->assertSee('Fast-Moving Item Ranking')
            ->assertSee('Purchase frequency')
            ->assertSeeInOrder(['#1', 'Frequent Filter', '#2', 'Bulk Chain'])
            ->assertSee('<strong>3</strong><small>paid receipts</small>', false)
            ->assertSee('10.0 per purchase');
    }

    public function test_guest_is_redirected_from_analytics_page(): void
    {
        $response = $this->get('/admin/analytics');

        $response->assertRedirect('/');
    }
}
