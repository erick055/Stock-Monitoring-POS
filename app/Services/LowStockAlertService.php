<?php

namespace App\Services;

use App\Jobs\SendStockAlertDigest;
use App\Models\Product;
use App\Models\StockAlertDelivery;
use App\Models\StockAlertSetting;
use App\Models\StockAlertState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class LowStockAlertService
{
    public function settings(): StockAlertSetting
    {
        return StockAlertSetting::query()->firstOrCreate(['id' => 1]);
    }

    public function checkAll(bool $force = false, bool $manual = false): int
    {
        $query = Product::query()->where('is_active', true);
        if ($manual) {
            $query->whereColumn('current_stock', '<=', 'reorder_level');
        }

        return $this->checkProducts($query->get(), $force, $manual);
    }

    public function checkProduct(Product $product, bool $force = false): int
    {
        return $this->checkProducts(collect([$product]), $force);
    }

    /** One run can enqueue at most one email, regardless of the number of products. */
    public function checkProducts(Collection $products, bool $force = false, bool $manual = false): int
    {
        $this->settings();

        return DB::transaction(function () use ($products, $force, $manual) {
            // Serialize checks, including creation of previously unseen product states.
            $settings = StockAlertSetting::query()->lockForUpdate()->findOrFail(1);
            $products = Product::query()->whereIn('product_id', $products->pluck('product_id'))->get();
            $states = StockAlertState::query()->whereIn('product_id', $products->pluck('product_id'))->lockForUpdate()->get()->keyBy('product_id');
            $eligible = collect();
            foreach ($products as $product) {
                $state = $states->get($product->product_id) ?? new StockAlertState(['product_id' => $product->product_id]);
                $severity = $product->is_active ? $this->severity($product) : 'healthy';
                if ($manual) {
                    if ($severity === 'critical') {
                        $eligible->push($product);
                    }
                    continue;
                }
                $previousSeverity = $state->exists ? $state->severity : 'healthy';
                $crossedReorder = $severity === 'critical' && $previousSeverity !== 'critical';
                if ($severity !== 'healthy' && ($force || $crossedReorder) && $this->canNotify($state)) {
                    $eligible->push($product);
                }
                $state->fill(['severity' => $severity, 'last_stock' => $product->current_stock, 'last_checked_at' => now()])->save();
            }
            if ((! $settings->email_enabled && ! $manual) || ! $settings->notification_email || $eligible->isEmpty()) {
                return 0;
            }

            return $this->queueDigest($settings, $eligible, 'immediate', manual: $manual);
        });
    }

    public function sendDailySummaryIfDue(bool $force = false): int
    {
        $this->settings();

        return DB::transaction(function () use ($force) {
            $settings = StockAlertSetting::query()->lockForUpdate()->findOrFail(1);
            if (! $settings->daily_summary_enabled || ! $settings->notification_email) {
                return 0;
            }
            if (! $force && (now()->format('H:i') < $settings->daily_summary_time || $settings->last_daily_summary_at?->isToday())) {
                return 0;
            }
            $products = Product::query()->where('is_active', true)->get()->filter(fn ($product) => $this->severity($product) !== 'healthy');
            return $this->queueDigest($settings, $products, 'daily_summary');
        });
    }

    private function canNotify(StockAlertState $state): bool
    {
        return ! $state->pending_delivery_id && (! $state->last_notified_at || $state->last_notified_at->lte(now()->subHours(24)));
    }

    private function queueDigest(StockAlertSetting $settings, Collection $products, string $type, bool $manual = false): int
    {
        $message = $this->digestMessage($products, $type);
        $subject = $this->dailySummarySubject($products);
        if ($type === 'immediate') {
            $subject = 'MotoSync Inventory Alert — '.$products->count().' Item(s) Need Attention';
        }
        $delivery = StockAlertDelivery::create([
            'product_id' => null, 'channel' => 'email', 'alert_type' => $type,
            'status' => 'queued', 'recipient' => $settings->notification_email, 'message' => $message,
        ]);
        if ($type === 'daily_summary') {
            // Use the delivery's timestamp so final failure can release this reservation exactly.
            $settings->update(['last_daily_summary_at' => $delivery->created_at]);
        } elseif (! $manual) {
            StockAlertState::whereIn('product_id', $products->pluck('product_id'))->update(['pending_delivery_id' => $delivery->id]);
        }
        if ($manual) {
            DB::afterCommit(function () use ($delivery, $subject) {
                $job = new SendStockAlertDigest($delivery->id, $subject);
                try {
                    $job->handle();
                } catch (\Throwable $error) {
                    $job->failed($error);
                    report($error);
                }
            });

            return $delivery->id;
        }
        Bus::dispatch(new SendStockAlertDigest($delivery->id, $subject));

        return 1;
    }

    public function criticalStockReport(): string
    {
        $products = Product::query()->where('is_active', true)
            ->whereColumn('current_stock', '<=', 'reorder_level')->get();

        return $this->digestMessage($products, 'immediate');
    }

    private function digestMessage(Collection $products, string $type): string
    {
        $products = $products->sortBy(fn ($product) => [
            $this->severity($product) === 'critical' ? 0 : 1, $product->current_stock, $product->product_id,
        ])->values();

        return $this->dailySummaryMessage($products, $type);
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

    private function dailySummaryMessage(Collection $products, string $type): string
    {
        $heading = $type === 'immediate' ? 'MotoSync Inventory Alert Digest' : 'MotoSync Daily Inventory Summary';
        if ($products->isEmpty()) {
            return implode("\n", [
                $heading,
                now()->format('F d, Y · h:i A'),
                '',
                $type === 'immediate' ? 'No active products are currently critical or out of stock.'
                    : 'Good news — no active products are currently within the low-stock warning range.',
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
            $heading,
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
}
