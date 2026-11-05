<?php

namespace App\Console\Commands;

use App\Services\LowStockAlertService;
use Illuminate\Console\Command;

class CheckLowStockAlerts extends Command
{
    protected $signature = 'stock-alerts:check {--force : Include all low products, respecting the 24-hour cooldown}';

    protected $description = 'Check inventory thresholds and send enabled low-stock alerts';

    public function handle(LowStockAlertService $alerts): int
    {
        $sent = $alerts->checkAll((bool) $this->option('force'));
        $this->info("Stock alert check completed; {$sent} notification(s) queued.");

        return self::SUCCESS;
    }
}
