<?php

namespace App\Jobs;

use App\Mail\InventoryAlertMail;
use App\Models\StockAlertDelivery;
use App\Models\StockAlertSetting;
use App\Models\StockAlertState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendStockAlertDigest implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [60, 300];

    public function __construct(public int $deliveryId, public string $subject)
    {
        // Always asynchronous, even if the app's default connection is sync.
        $this->onConnection('stock-alerts');
    }

    public function handle(): void
    {
        try {
            DB::transaction(function () {
                $delivery = StockAlertDelivery::query()->lockForUpdate()->find($this->deliveryId);
                if (! $delivery || in_array($delivery->status, ['sent', 'failed'], true)) {
                    return;
                }
                Mail::to($delivery->recipient)->send(new InventoryAlertMail($this->subject, $delivery->message));
                $delivery->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
                StockAlertState::where('pending_delivery_id', $delivery->id)->update([
                    'last_notified_at' => now(), 'pending_delivery_id' => null,
                ]);
            });
        } catch (Throwable $error) {
            StockAlertDelivery::whereKey($this->deliveryId)->where('status', '!=', 'sent')->update([
                'status' => 'retrying', 'error' => $error->getMessage(),
            ]);
            throw $error;
        }
    }

    public function failed(?Throwable $error): void
    {
        DB::transaction(function () use ($error) {
            // Match the settings-before-state lock order of alert checks.
            StockAlertSetting::query()->whereKey(1)->lockForUpdate()->first();
            $delivery = StockAlertDelivery::query()->lockForUpdate()->find($this->deliveryId);
            if (! $delivery || in_array($delivery->status, ['sent', 'failed'], true)) {
                return;
            }
            $delivery->update(['status' => 'failed', 'error' => $error?->getMessage()]);
            StockAlertState::where('pending_delivery_id', $delivery->id)->update(['pending_delivery_id' => null]);
            if ($delivery->alert_type === 'daily_summary') {
                StockAlertSetting::where('id', 1)->where('last_daily_summary_at', $delivery->created_at)->update(['last_daily_summary_at' => null]);
            }
        });
    }
}
