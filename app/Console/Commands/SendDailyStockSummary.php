<?php

namespace App\Console\Commands;

use App\Services\LowStockAlertService;
use Illuminate\Console\Command;

class SendDailyStockSummary extends Command
{
    protected $signature = 'stock-alerts:daily-summary {--force : Send now regardless of configured time}';

    protected $description = 'Send the configured daily low-stock email summary';

    public function handle(LowStockAlertService $alerts): int
    {
        $sent = $alerts->sendDailySummaryIfDue((bool) $this->option('force'));
        $this->info($sent ? 'Daily stock summary sent.' : 'Daily stock summary was not due or is disabled.');

        return self::SUCCESS;
    }
}
