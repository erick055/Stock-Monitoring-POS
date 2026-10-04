<?php

namespace App\Console\Commands;

use App\Services\DeadStockMachineLearning;
use App\Services\ProductDemandMachineLearning;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class RefreshInventoryPredictions extends Command
{
    protected $signature = 'ml:refresh';
    protected $description = 'Train and refresh local demand and dead-stock predictions outside web requests';

    public function handle(ProductDemandMachineLearning $demand, DeadStockMachineLearning $deadStock): int
    {
        $lock = Cache::lock('ml:refresh-lock', 3600);
        if (! $lock->get()) {
            $this->info('A model refresh is already running.');
            return self::SUCCESS;
        }
        try {
            $result = $demand->refresh();
            $this->info($result['available'] ? 'Future demand predictions refreshed.' : $result['message']);
            try {
                $deadStock->trainAndPredict();
                $this->info('Dead-stock predictions refreshed.');
            } catch (RuntimeException $exception) {
                $this->warn($exception->getMessage());
            }
            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
