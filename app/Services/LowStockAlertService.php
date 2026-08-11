<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockAlertDelivery;
use App\Models\StockAlertSetting;
use App\Models\StockAlertState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

class LowStockAlertService
{
    public function __construct(private readonly SmsAlertSender $sms)
    {
    }

    public function settings(): StockAlertSetting
    {
        return StockAlertSetting::query()->firstOrCreate(['id' => 1]);
    }

    public function checkAll(bool $force = false): int
    {
        return Product::query()
            ->where('is_active', true)
            ->get()
            ->sum(fn (Product $product) => $this->checkProduct($product, $force));
    }

    public function checkProduct(Product $product, bool $force = false): int
    {
        $settings = $this->settings();
        $severity = $this->severity($product);
        $state = StockAlertState::query()->firstOrNew(['product_id' => $product->product_id]);
        $previousSeverity = $state->exists ? $state->severity : 'healthy';
        $shouldNotify = $severity !== 'healthy'
            && ($force || $this->severityRank($severity) > $this->severityRank($previousSeverity));

        $state->fill([
            'severity' => $severity,
            'last_stock' => $product->current_stock,
            'last_checked_at' => now(),
        ])->save();

        if (! $shouldNotify || (! $settings->email_enabled && ! $settings->sms_enabled)) {
            return 0;
        }

        $message = sprintf(
            '%s stock alert: %s (%s) has %d units remaining; reorder level is %d.',
            strtoupper($severity),
            $product->name,
            $product->sku,
            $product->current_stock,
            $product->reorder_level,
        );

        $sent = 0;
        if ($settings->email_enabled && $settings->notification_email) {
            $sent += $this->sendEmail(
                $settings->notification_email,
                "MotoSync {$severity} stock alert: {$product->sku}",
                $message,
                'immediate',
                $product,
            );
        }
        if ($settings->sms_enabled && $settings->notification_phone) {
            $sent += $this->sendSms($settings->notification_phone, $message, 'immediate', $product);
        }

        return $sent;
    }

    public function sendDailySummaryIfDue(bool $force = false): int
    {
        $settings = $this->settings();
        if (! $settings->daily_summary_enabled || ! $settings->notification_email) {
            return 0;
        }
        if (! $force && now()->format('H:i') < $settings->daily_summary_time) {
            return 0;
        }
        if (! $force && $settings->last_daily_summary_at?->isToday()) {
            return 0;
        }

        $products = Product::query()->where('is_active', true)->get()
            ->filter(fn (Product $product) => $this->severity($product) !== 'healthy')
            ->sortBy(fn (Product $product) => [$this->severity($product) === 'critical' ? 0 : 1, $product->current_stock])
            ->values();

        $message = $this->dailySummaryMessage($products);
        $sent = $this->sendEmail(
            $settings->notification_email,
            'MotoSync daily low-stock summary - '.now()->format('M d, Y'),
            $message,
            'daily_summary',
        );

        if ($sent) {
            $settings->update(['last_daily_summary_at' => now()]);
        }

        return $sent;
    }

    public function severity(Product $product): string
    {
        if ($product->current_stock <= $product->reorder_level) {
            return 'critical';
        }
        if ($product->current_stock <= ($product->reorder_level * 2)) {
            return 'warning';
        }

        return 'healthy';
    }

    private function dailySummaryMessage(Collection $products): string
    {
        if ($products->isEmpty()) {
            return "MotoSync Daily Stock Summary\n\nNo low-stock products today.";
        }

        $lines = $products->map(fn (Product $product) => sprintf(
            '- [%s] %s (%s): %d units, reorder at %d',
            strtoupper($this->severity($product)),
            $product->name,
            $product->sku,
            $product->current_stock,
            $product->reorder_level,
        ));

        return "MotoSync Daily Stock Summary\n".now()->format('M d, Y')."\n\n".$lines->join("\n");
    }

    private function sendEmail(string $recipient, string $subject, string $message, string $type, ?Product $product = null): int
    {
        try {
            Mail::raw($message, fn ($mail) => $mail->to($recipient)->subject($subject));
            $this->recordDelivery($product, 'email', $type, 'sent', $recipient, $message);

            return 1;
        } catch (Throwable $error) {
            $this->recordDelivery($product, 'email', $type, 'failed', $recipient, $message, $error->getMessage());

            return 0;
        }
    }

    private function sendSms(string $recipient, string $message, string $type, ?Product $product = null): int
    {
        try {
            $this->sms->send($recipient, $message);
            $this->recordDelivery($product, 'sms', $type, 'sent', $recipient, $message);

            return 1;
        } catch (Throwable $error) {
            $this->recordDelivery($product, 'sms', $type, 'failed', $recipient, $message, $error->getMessage());

            return 0;
        }
    }

    private function recordDelivery(?Product $product, string $channel, string $type, string $status, string $recipient, string $message, ?string $error = null): void
    {
        StockAlertDelivery::create([
            'product_id' => $product?->product_id,
            'channel' => $channel,
            'alert_type' => $type,
            'status' => $status,
            'recipient' => $recipient,
            'message' => $message,
            'error' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }

    private function severityRank(string $severity): int
    {
        return ['healthy' => 0, 'warning' => 1, 'critical' => 2][$severity] ?? 0;
    }
}
