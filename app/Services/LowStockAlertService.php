<?php

namespace App\Services;

use App\Mail\InventoryAlertMail;
use App\Models\Product;
use App\Models\StockAlertDelivery;
use App\Models\StockAlertSetting;
use App\Models\StockAlertState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

class LowStockAlertService
{
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

        if (! $shouldNotify || ! $settings->email_enabled) {
            return 0;
        }

        $sent = 0;
        if ($settings->email_enabled && $settings->notification_email) {
            $sent += $this->sendEmail(
                $settings->notification_email,
                $this->immediateEmailSubject($product, $severity),
                $this->immediateEmailMessage($product, $severity),
                'immediate',
                $product,
            );
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
            $this->dailySummarySubject($products),
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
            return implode("\n", [
                'MotoSync Daily Inventory Summary',
                now()->format('F d, Y · h:i A'),
                '',
                'Good news — no active products are currently within the low-stock warning range.',
                '',
                'No replenishment action is required at this time. Continue monitoring sales and incoming stock movements.',
                '',
                'This is an automated inventory notification from MotoSync.',
            ]);
        }

        $outOfStock = $products->where('current_stock', 0)->count();
        $critical = $products->filter(fn (Product $product) => $this->severity($product) === 'critical')->count();
        $warning = $products->count() - $critical;
        $lines = $products->values()->map(fn (Product $product, int $index) => sprintf(
            '%d. [%s] %s (%s) | Stock: %d | Reorder: %d | Suggested restock: %d+',
            $index + 1,
            $this->statusLabel($product, $this->severity($product)),
            $product->name,
            $product->sku,
            $product->current_stock,
            $product->reorder_level,
            $this->recommendedRestock($product),
        ));

        return implode("\n", [
            'MotoSync Daily Inventory Summary',
            now()->format('F d, Y · h:i A'),
            '',
            'Priority overview',
            "- {$products->count()} product(s) require attention",
            "- {$critical} critical (including {$outOfStock} out of stock)",
            "- {$warning} warning",
            '',
            'Products to review',
            $lines->join("\n"),
            '',
            'Recommended next steps',
            '1. Replenish out-of-stock and critical items first.',
            '2. Confirm pending supplier deliveries before creating duplicate orders.',
            '3. Review recent POS demand and adjust reorder levels where needed.',
            '',
            'Open MotoSync > Low Stock Alerts for the latest quantities and notification history.',
            '',
            'This is an automated inventory notification from MotoSync.',
        ]);
    }

    private function immediateEmailSubject(Product $product, string $severity): string
    {
        return sprintf(
            'MotoSync [%s] Inventory Alert — %s',
            $this->statusLabel($product, $severity),
            $product->sku,
        );
    }

    private function immediateEmailMessage(Product $product, string $severity): string
    {
        $status = $this->statusLabel($product, $severity);
        $restock = $this->recommendedRestock($product);
        $action = match (true) {
            $product->current_stock === 0 => "Restock at least {$restock} unit(s) before confirming new orders for this item.",
            $severity === 'critical' => "Arrange replenishment now. Adding at least {$restock} unit(s) will move the item above its warning range.",
            default => "Plan replenishment soon. Adding at least {$restock} unit(s) will move the item above its warning range.",
        };

        return implode("\n", [
            'MotoSync Inventory Alert',
            '',
            "Status: {$status}",
            "Product: {$product->name}",
            "SKU: {$product->sku}",
            "Current stock: {$product->current_stock} unit(s)",
            "Reorder level: {$product->reorder_level} unit(s)",
            "Suggested restock: {$restock}+ unit(s)",
            '',
            'Recommended action',
            $action,
            '',
            'Open MotoSync > Low Stock Alerts to review demand, update alert settings, and document the next action.',
            '',
            'Alert generated: '.now()->format('F d, Y · h:i A'),
            'This is an automated inventory notification from MotoSync.',
        ]);
    }

    private function dailySummarySubject(Collection $products): string
    {
        if ($products->isEmpty()) {
            return 'MotoSync Daily Inventory Summary — No Low-Stock Items';
        }

        $critical = $products->filter(fn (Product $product) => $this->severity($product) === 'critical')->count();

        return sprintf(
            'MotoSync Daily Inventory Summary — %d Item(s) Need Attention, %d Critical',
            $products->count(),
            $critical,
        );
    }

    private function statusLabel(Product $product, string $severity): string
    {
        return $product->current_stock === 0 ? 'OUT OF STOCK' : strtoupper($severity);
    }

    private function recommendedRestock(Product $product): int
    {
        $healthyTarget = max(1, ($product->reorder_level * 2) + 1);

        return max(1, $healthyTarget - $product->current_stock);
    }

    private function sendEmail(string $recipient, string $subject, string $message, string $type, ?Product $product = null): int
    {
        try {
            Mail::to($recipient)->send(new InventoryAlertMail($subject, $message));
            $this->recordDelivery($product, 'email', $type, 'sent', $recipient, $message);

            return 1;
        } catch (Throwable $error) {
            $this->recordDelivery($product, 'email', $type, 'failed', $recipient, $message, $error->getMessage());

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
