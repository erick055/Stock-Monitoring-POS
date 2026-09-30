<?php

namespace Tests\Feature;

use App\Mail\InventoryAlertMail;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\StockAlertDelivery;
use App\Models\StockAlertSetting;
use App\Models\StockAlertState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LowStocksPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_low_stocks_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/admin/low-stocks');

        $response->assertOk();
        $response->assertSee('Stock Alerts and Monitoring');
    }

    public function test_low_stocks_page_shows_live_product_alerts_and_pos_demand(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $critical = Product::create([
            'sku' => 'OIL-LOW',
            'name' => 'Engine Oil Low',
            'category' => 'Oils',
            'unit_price' => 250,
            'current_stock' => 3,
            'reorder_level' => 5,
        ]);
        $warning = Product::create([
            'sku' => 'TIRE-WARN',
            'name' => 'Tire Warning',
            'category' => 'Tires',
            'unit_price' => 800,
            'current_stock' => 9,
            'reorder_level' => 5,
        ]);
        Product::create([
            'sku' => 'CHAIN-OK',
            'name' => 'Healthy Chain',
            'category' => 'Chains',
            'unit_price' => 600,
            'current_stock' => 40,
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
            'product_id' => $critical->product_id,
            'quantity' => 2,
            'unit_sale_price' => 250,
            'unit_cost' => 100,
            'line_total' => 500,
        ]);

        $response = $this->actingAs($admin)->get('/admin/low-stocks');

        $response->assertOk()
            ->assertSee('Engine Oil Low')
            ->assertSee('Tire Warning')
            ->assertDontSee('Healthy Chain')
            ->assertSee('2 units')
            ->assertSee('Critical')
            ->assertSee('Warning');
    }

    public function test_low_stock_alerts_are_searchable_filtered_and_paginated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (range(1, 35) as $index) {
            Product::create([
                'sku' => sprintf('LOW-%03d', $index),
                'name' => "Low Product {$index}",
                'category' => $index === 35 ? 'Special Category' : 'General',
                'unit_price' => 100,
                'current_stock' => $index % 2 ? 2 : 8,
                'reorder_level' => 5,
            ]);
        }

        $this->actingAs($admin)->get(route('admin.low-stocks'))
            ->assertOk()
            ->assertViewHas('activeAlerts', fn ($alerts) => $alerts->total() === 35 && $alerts->count() === 25 && $alerts->lastPage() === 2);

        $this->actingAs($admin)->get(route('admin.low-stocks', ['status' => 'critical', 'per_page' => 10]))
            ->assertOk()
            ->assertViewHas('activeAlerts', fn ($alerts) => $alerts->total() === 18 && $alerts->count() === 10);

        $this->actingAs($admin)->get(route('admin.low-stocks', ['search' => 'Special Category']))
            ->assertOk()
            ->assertSee('Low Product 35')
            ->assertDontSee('Low Product 34');
    }

    public function test_staff_cannot_view_low_stocks_page(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
        ]);

        $response = $this->actingAs($staff)->get('/admin/low-stocks');

        $response->assertForbidden();
    }

    public function test_admin_can_save_email_and_daily_summary_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.low-stocks.settings'), [
            'email_enabled' => 1,
            'daily_summary_enabled' => 1,
            'notification_email' => 'alerts@example.com',
            'daily_summary_time' => '08:30',
        ])->assertRedirect()->assertSessionHas('success');

        $settings = StockAlertSetting::firstOrFail();
        $this->assertTrue($settings->email_enabled);
        $this->assertTrue($settings->daily_summary_enabled);
        $this->assertSame('alerts@example.com', $settings->notification_email);
        $this->assertSame('08:30', $settings->daily_summary_time);
    }

    public function test_stock_crossing_warning_threshold_sends_email_once(): void
    {
        Mail::fake();
        Http::fake();

        StockAlertSetting::create([
            'email_enabled' => true,
            'notification_email' => 'alerts@example.com',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'ALERT-01', 'name' => 'Alert Product', 'unit_price' => 100,
            'current_stock' => 11, 'reorder_level' => 5,
        ]);

        $payload = [
            'product_id' => $product->product_id,
            'movement_type' => 'out',
            'quantity' => 2,
            'reason_code' => 'SALE',
        ];
        $this->actingAs($admin)->post(route('admin.inventory.movements.store'), $payload)->assertSessionHas('success');

        $this->assertDatabaseHas('stock_alert_deliveries', ['channel' => 'email', 'alert_type' => 'immediate', 'status' => 'sent']);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('stock_alert_deliveries', ['channel' => 'sms']);

        $emailMessage = StockAlertDelivery::where('channel', 'email')->value('message');
        $this->assertStringContainsString('MotoSync Inventory Alert', $emailMessage);
        $this->assertStringContainsString('Recommended action', $emailMessage);
        $this->assertStringContainsString('Suggested restock: 2+ unit(s)', $emailMessage);
        $this->assertStringContainsString('Alert generated:', $emailMessage);
        Mail::assertSent(InventoryAlertMail::class, function (InventoryAlertMail $mail) {
            $html = $mail->render();

            return str_contains($html, '<strong style="font-weight:700;color:#111318;">Status:</strong>')
                && str_contains($html, '<strong style="font-weight:700;color:#111318;">Product:</strong>')
                && str_contains($html, '<strong style="font-weight:700;color:#111318;">SKU:</strong>')
                && str_contains($html, '<strong style="font-weight:700;color:#111318;">Current stock:</strong>')
                && str_contains($html, '<strong style="font-weight:700;color:#111318;">Reorder level:</strong>')
                && str_contains($html, '<strong style="font-weight:700;color:#111318;">Suggested restock:</strong>');
        });

        $this->actingAs($admin)->post(route('admin.inventory.movements.store'), [
            ...$payload, 'quantity' => 1,
        ])->assertSessionHas('success');
        $this->assertSame(1, StockAlertDelivery::where('alert_type', 'immediate')->count());
    }

    public function test_daily_summary_command_sends_one_documented_email(): void
    {
        Mail::fake();
        StockAlertSetting::create([
            'daily_summary_enabled' => true,
            'notification_email' => 'summary@example.com',
            'daily_summary_time' => '08:00',
        ]);
        Product::create([
            'sku' => 'DAILY-LOW', 'name' => 'Daily Low Product', 'unit_price' => 100,
            'current_stock' => 2, 'reorder_level' => 5,
        ]);

        Artisan::call('stock-alerts:daily-summary', ['--force' => true]);

        $this->assertDatabaseHas('stock_alert_deliveries', [
            'channel' => 'email', 'alert_type' => 'daily_summary', 'status' => 'sent',
            'recipient' => 'summary@example.com',
        ]);
        $summary = StockAlertDelivery::where('alert_type', 'daily_summary')->value('message');
        $this->assertStringContainsString('MotoSync Daily Inventory Summary', $summary);
        $this->assertStringContainsString('Priority overview', $summary);
        $this->assertStringContainsString('Daily Low Product (DAILY-LOW)', $summary);
        $this->assertStringContainsString('Suggested restock: 9+', $summary);
        $this->assertStringContainsString('Recommended next steps', $summary);
        $this->assertNotNull(StockAlertSetting::firstOrFail()->last_daily_summary_at);
    }

    public function test_low_stock_page_renders_existing_delivery_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        StockAlertDelivery::create([
            'channel' => 'email',
            'alert_type' => 'immediate',
            'status' => 'sent',
            'recipient' => 'alerts@example.com',
            'message' => str_repeat('Documented low-stock notification. ', 5),
            'sent_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.low-stocks'))
            ->assertOk()
            ->assertSee('Notification History')
            ->assertSee('alerts@example.com');
    }

    public function test_run_now_returns_to_a_renderable_notification_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        StockAlertDelivery::create([
            'channel' => 'email', 'alert_type' => 'immediate', 'status' => 'failed',
            'recipient' => '+639171234567', 'message' => 'Alert body', 'error' => 'Provider unavailable',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.low-stocks'))
            ->post(route('admin.low-stocks.run-now'))
            ->assertRedirect(route('admin.low-stocks'))
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->get(route('admin.low-stocks'))
            ->assertOk()
            ->assertSee('Provider unavailable');
    }

    public function test_run_now_forces_a_new_delivery_for_an_existing_low_stock_state(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        StockAlertSetting::create([
            'email_enabled' => true,
            'notification_email' => 'alerts@example.com',
        ]);
        $product = Product::create([
            'sku' => 'MANUAL-ALERT', 'name' => 'Manual Alert Product', 'unit_price' => 100,
            'current_stock' => 2, 'reorder_level' => 5,
        ]);
        StockAlertState::create([
            'product_id' => $product->product_id,
            'severity' => 'critical',
            'last_stock' => 2,
            'last_checked_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.low-stocks.run-now'))
            ->assertSessionHas('success', 'Stock alert check completed. 1 new notification(s) sent.');

        $this->assertDatabaseHas('stock_alert_deliveries', [
            'product_id' => $product->product_id,
            'channel' => 'email',
            'status' => 'sent',
        ]);
    }

    public function test_legacy_sms_settings_cannot_send_notifications_or_appear_on_the_page(): void
    {
        Http::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $settings = new StockAlertSetting;
        $settings->forceFill([
            'id' => 1,
            'email_enabled' => false,
            'sms_enabled' => true,
            'notification_phone' => '+639171234567',
        ])->save();
        Product::create([
            'sku' => 'LOG-SMS-01', 'name' => 'Log SMS Product', 'unit_price' => 100,
            'current_stock' => 1, 'reorder_level' => 5,
        ]);

        $this->actingAs($admin)->post(route('admin.low-stocks.run-now'))
            ->assertSessionHas('success', 'Stock alert check completed. 0 new notification(s) sent.');

        Http::assertNothingSent();
        $this->assertDatabaseMissing('stock_alert_deliveries', ['channel' => 'sms']);
        $this->actingAs($admin)->get(route('admin.low-stocks'))
            ->assertOk()->assertDontSee('SMS Alerts')->assertDontSee('notification_phone');
    }

    public function test_guest_is_redirected_from_low_stocks_page(): void
    {
        $response = $this->get('/admin/low-stocks');

        $response->assertRedirect('/');
    }
}
