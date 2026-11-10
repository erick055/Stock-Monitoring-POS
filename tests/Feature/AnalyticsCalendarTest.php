<?php

namespace Tests\Feature;

use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_filters_transactions_to_the_selected_day_including_its_boundaries(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        foreach ([['2026-11-02 23:59:59', 10, 'paid'], ['2026-11-03 00:00:00', 100, 'paid'], ['2026-11-03 23:59:59', 200, 'paid'], ['2026-11-04 00:00:00', 400, 'paid'], ['2026-11-03 12:00:00', 900, 'cancelled']] as [$date, $total, $status]) {
            SalesTransaction::create(['staff_id' => $staff->id, 'sale_date' => $date, 'total_sale_amount' => $total, 'payment_status' => $status]);
        }
        $response = $this->actingAs($owner)->get(route('admin.analytics', ['period' => 'day', 'transaction_date' => '2026-11-03']))
            ->assertOk()->assertSee('Choose a specific date')->assertSee('Transactions for November 3, 2026');
        $this->assertSame(2, $response->viewData('dayTransactions')->total());
        $this->assertSame(300.0, $response->viewData('dailySalesTotal'));
        foreach ($response->viewData('dayTransactions') as $sale) $this->assertSame('2026-11-03', $sale->sale_date->toDateString());
        $this->assertSame('day', $response->viewData('salesPeriod'));
        $this->assertCount(1, $response->viewData('weeklySales'));
        $this->assertSame(300.0, $response->viewData('weeklySales')->first()['total']);
    }

    public function test_daily_pagination_keeps_the_date_and_empty_days_have_zero_totals(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        for ($i = 0; $i < 26; $i++) SalesTransaction::create(['staff_id' => $owner->id, 'sale_date' => '2024-02-29 12:00:00', 'total_sale_amount' => 10, 'payment_status' => 'paid']);
        $response = $this->actingAs($owner)->get(route('admin.analytics', ['transaction_date' => '2024-02-29', 'period' => 'day', 'range' => '2024-02-29']))->assertOk();
        $this->assertCount(20, $response->viewData('dayTransactions'));
        $this->assertSame(26, $response->viewData('dayTransactions')->total());
        $this->assertSame(260.0, $response->viewData('dailySalesTotal'));
        $url = $response->viewData('dayTransactions')->nextPageUrl();
        $this->assertStringContainsString('transaction_date=2024-02-29', $url);
        $this->assertStringContainsString('period=day', $url);
        $this->get($url)->assertOk()->assertViewHas('dayTransactions', fn ($rows) => $rows->count() === 6);
        $export = $this->get(route('admin.analytics.export', ['transaction_date' => '2024-02-29']))->assertOk();
        $path = $export->baseResponse->getFile()->getPathname();
        $reader = new \OpenSpout\Reader\XLSX\Reader;
        $exported = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== 'Daily Transactions') continue;
                foreach ($sheet->getRowIterator() as $row) $exported[] = $row->toArray();
            }
        } finally { $reader->close(); unlink($path); }
        $this->assertCount(27, $exported);
        foreach (array_slice($exported, 1) as $row) $this->assertStringStartsWith('2024-02-29', $row[1]);
        $empty = $this->get(route('admin.analytics', ['period' => 'day', 'transaction_date' => '2024-03-01']))->assertOk()->assertSee('No paid transactions on March 1, 2024.');
        $this->assertSame(0.0, $empty->viewData('dailySalesTotal'));
        $this->assertSame(0, $empty->viewData('dayTransactions')->total());
    }

    public function test_calendar_rejects_invalid_dates_and_is_owner_only(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner)->getJson(route('admin.analytics', ['transaction_date' => '2026-02-30']))->assertUnprocessable();
        $this->getJson(route('admin.analytics', ['calendar_month' => '2026-13']))->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'staff']))->get(route('admin.analytics', ['period' => 'day', 'transaction_date' => '2026-11-03']))->assertForbidden();
    }

    public function test_specific_date_opens_the_transactions_tab_and_uses_one_native_calendar(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner)->get(route('admin.analytics'))->assertOk()->assertSee('Specific date')
            ->assertViewHas('analyticsTab', 'overview')->assertDontSee('Transaction calendar');
        $response = $this->get(route('admin.analytics', ['period' => 'day', 'range' => '2026-09-15']))->assertOk()
            ->assertSee('Sales for a Specific Date')->assertSee('Transactions for September 15, 2026');
        $response->assertSee('type="date"', false)->assertDontSee('calendar-day-grid');
        $response->assertViewHas('analyticsTab', 'transactions');
        $this->assertSame('2026-09-15', $response->viewData('selectedTransactionDate')->toDateString());
        $this->assertSame(1, substr_count($response->getContent(), 'class="specific-date-view"'));
    }
}
