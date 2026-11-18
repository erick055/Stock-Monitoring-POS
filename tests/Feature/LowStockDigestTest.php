<?php

namespace Tests\Feature;

use App\Jobs\SendStockAlertDigest;
use App\Mail\InventoryAlertMail;
use App\Models\Product;
use App\Models\StockAlertDelivery;
use App\Models\StockAlertSetting;
use App\Models\StockAlertState;
use App\Models\User;
use App\Services\LowStockAlertService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class LowStockDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_check_of_one_hundred_products_sends_one_digest_and_records_each_click(): void
    {
        Mail::fake();
        Queue::fake();
        $owner = User::factory()->create(['role' => 'admin']);
        StockAlertSetting::create(['email_enabled' => true, 'notification_email' => 'alerts@example.com']);
        for ($i = 1; $i <= 100; $i++) {
            Product::create(['sku' => 'B2-'.$i, 'name' => 'Critical part '.$i, 'current_stock' => 0, 'reorder_level' => 5]);
        }
        Product::create(['sku' => 'B2-WARNING', 'name' => 'Warning part', 'current_stock' => 8, 'reorder_level' => 5]);
        $this->actingAs($owner)->post(route('admin.low-stocks.run-now'))->assertRedirect();
        Mail::assertSent(InventoryAlertMail::class, 1);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('stock_alert_deliveries', 1);
        $delivery = StockAlertDelivery::firstOrFail();
        $this->assertSame('sent', $delivery->status);
        $this->assertNull($delivery->product_id);
        foreach (['B2-1', 'B2-100'] as $sku) {
            $this->assertStringContainsString($sku, $delivery->message);
        }
        $this->assertStringNotContainsString('B2-WARNING', $delivery->message);
        $this->get(route('admin.low-stocks.export-pdf'))->assertOk()
            ->assertSee('Print / Save as PDF')
            ->assertSee('MotoSync Inventory Alert Digest')
            ->assertSee('B2-100')
            ->assertDontSee('B2-WARNING');
        Mail::assertSent(InventoryAlertMail::class, 1);
        $this->assertDatabaseCount('stock_alert_deliveries', 1);
        $this->post(route('admin.low-stocks.run-now'))->assertRedirect();
        Mail::assertSent(InventoryAlertMail::class, 2);
        $this->assertDatabaseCount('stock_alert_deliveries', 2);
    }

    public function test_delivery_uses_the_database_queue_even_with_a_sync_default(): void
    {
        Mail::fake();
        config(['queue.default' => 'sync']);
        StockAlertSetting::create(['email_enabled' => true, 'notification_email' => 'alerts@example.com']);
        Product::create(['sku' => 'ASYNC', 'name' => 'Queued part', 'current_stock' => 0, 'reorder_level' => 5]);
        $alerts = app(LowStockAlertService::class);
        $this->assertSame(1, $alerts->checkAll(force: true));
        Mail::assertNothingSent();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('stock_alert_deliveries', 1);
        $state = StockAlertState::firstOrFail();
        $this->assertNull($state->last_notified_at);
        $this->assertNotNull($state->pending_delivery_id);
        $workerJob = Queue::connection('stock-alerts')->pop();
        $this->assertNotNull($workerJob);
        $workerJob->fire();
        $workerJob->delete();
        Mail::assertSent(InventoryAlertMail::class, 1);
        $this->assertNotNull($state->fresh()->last_notified_at);
        $this->assertNull($state->fresh()->pending_delivery_id);
        $this->assertSame('sent', StockAlertDelivery::firstOrFail()->status);
        $this->assertSame(0, $alerts->checkAll(force: true));
    }

    public function test_immediate_alerts_require_a_new_reorder_crossing_and_respect_the_24_hour_cooldown(): void
    {
        Mail::fake();
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-11-03 10:00:00'));
        StockAlertSetting::create(['email_enabled' => true, 'notification_email' => 'alerts@example.com']);
        $product = Product::create(['sku' => 'CROSS', 'name' => 'Crossing part', 'current_stock' => 11, 'reorder_level' => 5]);
        $alerts = app(LowStockAlertService::class);
        $this->assertSame(0, $alerts->checkProduct($product));
        $product->update(['current_stock' => 8]);
        $this->assertSame(0, $alerts->checkProduct($product)); // Warning is included in manual digests, not an immediate crossing.
        $product->update(['current_stock' => 5]);
        $this->assertSame(1, $alerts->checkProduct($product));
        $job = Queue::pushed(SendStockAlertDigest::class)->first();
        $job->handle();
        $this->assertSame(0, $alerts->checkProduct($product));
        $product->update(['current_stock' => 8]);
        $alerts->checkProduct($product);
        $product->update(['current_stock' => 4]);
        $this->assertSame(0, $alerts->checkProduct($product));
        $this->travel(23)->hours();
        $this->assertSame(0, $alerts->checkAll(force: true));
        $this->travel(1)->hours();
        $this->assertSame(0, $alerts->checkProduct($product)); // Remaining low is not a new crossing.
        $product->update(['current_stock' => 8]);
        $alerts->checkProduct($product);
        $product->update(['current_stock' => 5]);
        $this->assertSame(1, $alerts->checkProduct($product));
        Queue::assertPushed(SendStockAlertDigest::class, 2);
    }

    public function test_multi_product_checkout_queues_one_immediate_digest(): void
    {
        Mail::fake();
        Queue::fake();
        $owner = User::factory()->create(['role' => 'admin']);
        StockAlertSetting::create(['email_enabled' => true, 'notification_email' => 'alerts@example.com']);
        $items = [];
        for ($i = 1; $i <= 20; $i++) {
            $product = Product::create(['sku' => 'CART-'.$i, 'name' => 'Cart part '.$i, 'current_stock' => 6, 'reorder_level' => 5, 'unit_price' => 100]);
            $items[] = ['product_id' => $product->product_id, 'quantity' => 2];
        }
        $this->actingAs($owner)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(route('admin.pos.checkout'), ['payment_method' => 'cash', 'amount_tendered' => 10000000, 'items' => $items])->assertCreated();
        Queue::assertPushed(SendStockAlertDigest::class, 1);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('stock_alert_deliveries', 1);
        $message = StockAlertDelivery::firstOrFail()->message;
        foreach (range(1, 20) as $i) {
            $this->assertStringContainsString('CART-'.$i.')', $message);
        }
    }

    public function test_a_final_failure_releases_reservations_without_marking_products_notified(): void
    {
        Queue::fake();
        StockAlertSetting::create(['email_enabled' => true, 'notification_email' => 'alerts@example.com']);
        Product::create(['sku' => 'FAIL', 'name' => 'Failed part', 'current_stock' => 0, 'reorder_level' => 5]);
        $alerts = app(LowStockAlertService::class);
        $this->assertSame(1, $alerts->checkAll(force: true));
        $job = Queue::pushed(SendStockAlertDigest::class)->first();
        $job->failed(new \RuntimeException('Mail unavailable'));
        $this->assertDatabaseHas('stock_alert_deliveries', ['status' => 'failed', 'error' => 'Mail unavailable']);
        $state = StockAlertState::firstOrFail();
        $this->assertNull($state->last_notified_at);
        $this->assertNull($state->pending_delivery_id);
        $this->assertSame(1, $alerts->checkAll());
        $this->assertSame(0, $alerts->checkAll());
        Queue::assertPushed(SendStockAlertDigest::class, 2);
    }

    public function test_a_queue_failure_rolls_back_the_history_and_product_reservations(): void
    {
        StockAlertSetting::create(['email_enabled' => true, 'notification_email' => 'alerts@example.com']);
        Product::create(['sku' => 'QUEUE-FAIL', 'name' => 'Queue failed part', 'current_stock' => 0, 'reorder_level' => 5]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        try {
            app(LowStockAlertService::class)->checkAll(force: true);
            $this->fail('An enqueue failure should roll back the reservation.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Queue unavailable', $error->getMessage());
        }
        $this->assertDatabaseCount('stock_alert_deliveries', 0);
        $this->assertDatabaseCount('stock_alert_states', 0);
    }

    public function test_daily_summary_queues_at_the_configured_manila_time_once_per_day(): void
    {
        Queue::fake();
        StockAlertSetting::create([
            'daily_summary_enabled' => true,
            'notification_email' => 'alerts@example.com',
            'daily_summary_time' => '18:30',
        ]);
        $alerts = app(LowStockAlertService::class);
        $this->travelTo(Carbon::parse('2026-11-13 18:29:59', 'Asia/Manila'));
        $this->assertSame(0, $alerts->sendDailySummaryIfDue());
        $this->travelTo(Carbon::parse('2026-11-13 18:30:00', 'Asia/Manila'));
        $this->assertSame(1, $alerts->sendDailySummaryIfDue());
        $this->travelTo(Carbon::parse('2026-11-13 18:31:00', 'Asia/Manila'));
        $this->assertSame(0, $alerts->sendDailySummaryIfDue());
        Queue::assertPushed(SendStockAlertDigest::class, 1);
    }

    public function test_daily_summary_can_send_after_an_immediate_alert_and_retry_after_failure(): void
    {
        Queue::fake();
        Mail::fake();
        StockAlertSetting::create(['email_enabled' => true, 'daily_summary_enabled' => true, 'notification_email' => 'alerts@example.com', 'daily_summary_time' => '00:00']);
        Product::create(['sku' => 'DAILY-RETRY', 'name' => 'Daily retry', 'current_stock' => 0, 'reorder_level' => 5]);
        $alerts = app(LowStockAlertService::class);
        $this->assertSame(1, $alerts->checkAll());
        Queue::pushed(SendStockAlertDigest::class)->first()->handle();
        $this->assertSame(1, $alerts->sendDailySummaryIfDue());
        $this->assertSame(0, $alerts->sendDailySummaryIfDue());
        $this->assertSame(0, $alerts->checkAll(force: true));
        $job = Queue::pushed(SendStockAlertDigest::class)->last();
        $job->failed(new \RuntimeException('Delivery failed'));
        $this->assertNull(StockAlertSetting::firstOrFail()->last_daily_summary_at);
        $this->assertSame(1, $alerts->sendDailySummaryIfDue());
        $job->failed(new \RuntimeException('Duplicate failure callback'));
        $this->assertNotNull(StockAlertSetting::firstOrFail()->last_daily_summary_at);
        $retry = Queue::pushed(SendStockAlertDigest::class)->last();
        $retry->handle();
        $this->assertSame(0, $alerts->checkAll(force: true));
        Mail::assertSent(InventoryAlertMail::class, 2);
    }
}
