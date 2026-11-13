<?php

namespace Tests\Feature;

use App\Models\CustomerReturn;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTarget;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\SalesTargetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SalesTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sets_and_updates_one_target_per_month_with_validation_and_staff_cannot_write(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner)->post(route('admin.analytics.targets.store'), ['target_month' => '2026-11', 'target_amount' => '50000'])
            ->assertRedirect(route('admin.analytics', ['tab' => 'targets']));
        $this->post(route('admin.analytics.targets.store'), ['target_month' => '2026-11', 'target_amount' => '60000.50'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales_targets', 1);
        $this->assertDatabaseHas('sales_targets', ['month' => '2026-11-01', 'amount' => 60000.50, 'updated_by' => $owner->id]);
        foreach ([['2026-13', 100], ['2026-11', 0], ['2026-11', -1], ['2026-11', 'NaN'], ['2026-11', 1.234], ['2026-11', 10000000000]] as [$month, $amount]) {
            $this->post(route('admin.analytics.targets.store'), ['target_month' => $month, 'target_amount' => $amount])->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('sales_targets', 1);
        $this->get(route('admin.analytics'))->assertOk()->assertViewHas('analyticsTab', 'targets')->assertSee('data-target-validation-error', false);
    }

    public function test_staff_cannot_read_or_write_store_targets(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->get(route('admin.analytics'))->assertForbidden();
        $this->post(route('admin.analytics.targets.store'), ['target_month' => '2026-11', 'target_amount' => 1])->assertForbidden();
        $this->get(route('staff.dashboard'))->assertOk()->assertDontSee('Monthly Sales Target');
        $this->assertDatabaseCount('sales_targets', 0);
    }

    public function test_progress_uses_paid_sales_and_refund_approval_dates_and_excludes_future_sales(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(12, 0));
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'QUOTA', 'name' => 'Target part']);
        SalesTarget::create(['month' => '2026-11-01', 'amount' => 50000]);
        SalesTransaction::create(['staff_id' => $owner->id, 'total_sale_amount' => 36000, 'payment_status' => 'paid', 'sale_date' => '2026-11-01 00:00:00']);
        $sale = SalesTransaction::create(['staff_id' => $owner->id, 'total_sale_amount' => 1000, 'payment_status' => 'paid', 'sale_date' => '2026-10-20 12:00:00']);
        foreach ([['paid', '2026-10-31 23:59:59'], ['paid', '2026-11-05 12:00:01'], ['cancelled', '2026-11-04 12:00:00']] as [$status, $date]) {
            SalesTransaction::create(['staff_id' => $owner->id, 'total_sale_amount' => 99999, 'payment_status' => $status, 'sale_date' => $date]);
        }
        CustomerReturn::create(['sale_id' => $sale->sale_id, 'product_id' => $product->product_id, 'quantity' => 1,
            'reason' => 'Return', 'refund_amount' => 1000, 'status' => 'approved', 'returned_at' => now()->subDays(10), 'approved_at' => now()]);
        CustomerReturn::create(['sale_id' => $sale->sale_id, 'product_id' => $product->product_id, 'quantity' => 1,
            'reason' => 'Waiting', 'refund_amount' => 500, 'status' => 'pending', 'returned_at' => now()]);
        $target = app(SalesTargetService::class)->current();
        $this->assertEquals(35000, $target['achieved']);
        $this->assertEquals(70, $target['percent']);
        $this->assertEquals(15000, $target['remaining']);
        $this->assertNull($target['gross_profit']);
        $this->assertSame('In progress', $target['status']);
        SalesTarget::query()->update(['amount' => 10000]);
        $target = app(SalesTargetService::class)->current();
        $this->assertEquals(350, $target['percent']);
        $this->assertEquals(100, $target['bar_percent']);
        $this->assertEquals(0, $target['remaining']);
        $this->assertSame('Achieved', $target['status']);
        $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk()->assertSee('Monthly Sales Target')->assertSee('350.0%');
    }

    public function test_monthly_card_shows_recovery_and_profit_after_sellable_and_damaged_returns(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(12, 0));
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'MARGIN', 'name' => 'Stock part', 'unit_cost' => 1200]);
        SalesTarget::create(['month' => '2026-11-01', 'amount' => 100000]);
        $sale = SalesTransaction::create(['staff_id' => $owner->id, 'total_sale_amount' => 115000,
            'payment_status' => 'paid', 'sale_date' => now()->subDay()]);
        SalesItem::create(['sale_id' => $sale->sale_id, 'product_id' => $product->product_id, 'quantity' => 115,
            'unit_sale_price' => 1000, 'unit_cost' => 700, 'line_total' => 115000]);

        foreach (['sellable' => 3, 'damaged' => 2] as $condition => $quantity) {
            CustomerReturn::create(['sale_id' => $sale->sale_id, 'product_id' => $product->product_id,
                'quantity' => $quantity, 'reason' => 'Return', 'item_condition' => $condition,
                'refund_amount' => 1000 * $quantity, 'status' => 'approved', 'returned_at' => now(), 'approved_at' => now()]);
        }

        $target = app(SalesTargetService::class)->current();
        $this->assertEquals(115000, $target['gross_sales']);
        $this->assertEquals(5000, $target['refunds']);
        $this->assertEquals(110000, $target['achieved']);
        $this->assertEquals(100000, $target['recovered']);
        $this->assertEquals(10000, $target['above_target']);
        $this->assertEquals(105000, $target['gross_sales_needed']);
        $this->assertEquals(31600, $target['gross_profit']);

        $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Stock Cost Recovery')->assertSee('110.0%')->assertSee('₱31,600.00')
            ->assertSee('sales-target-refunds')->assertSee('sales-target-surplus');
        $this->get(route('admin.analytics', ['tab' => 'targets']))->assertOk()
            ->assertSee('Gross Sales Needed After Refunds')->assertSee('₱105,000.00');
    }

    public function test_history_is_paginated_with_past_and_future_statuses_and_panels_render_once(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(12, 0));
        $owner = User::factory()->create(['role' => 'admin']);
        foreach (range(0, 24) as $offset) {
            SalesTarget::create(['month' => now()->startOfMonth()->addMonths($offset - 23)->toDateString(), 'amount' => 50000]);
        }
        $request = Request::create('/admin/analytics?target_rows=20');
        $rows = app(SalesTargetService::class)->history($request);
        $this->assertSame(25, $rows->total());
        $this->assertCount(20, $rows);
        $this->assertSame('Not started', $rows[0]['status']);
        $this->assertSame('In progress', $rows[1]['status']);
        $this->assertSame('Below target', $rows[2]['status']);
        $response = $this->actingAs($owner)->get(route('admin.analytics', ['tab' => 'targets']));
        $response->assertOk()->assertSee('Sales Targets')->assertSee('Monthly results');
        foreach (['overview', 'transactions', 'movement', 'ai', 'targets'] as $key) {
            $this->assertSame(1, substr_count($response->getContent(), 'id="analytics-'.$key.'"'));
        }
        $this->assertSame(1, substr_count($response->getContent(), 'id="transaction-history"'));
        $this->assertSame(1, substr_count($response->getContent(), 'id="sales-target-history"'));
        $this->get(route('admin.analytics', ['target_rows' => 50]))->assertOk()->assertViewHas('targetRows', fn ($rows) => $rows->count() === 25);
    }
}
