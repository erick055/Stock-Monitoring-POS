<?php

namespace App\Console\Commands;

use App\Services\DeadStockMachineLearning;
use Illuminate\Console\Command;
use RuntimeException;

class TrainDeadStockModel extends Command
{
    protected $signature = 'inventory:train-dead-stock-model';
    protected $description = 'Train the local dead-stock ML model and refresh product predictions';
    public function handle(DeadStockMachineLearning $ml): int
    {
        try { $result = $ml->trainAndPredict(); }
        catch (RuntimeException $e) { $this->error($e->getMessage()); return self::FAILURE; }
        $this->info("Trained {$result['model']->version} with {$result['model']->training_samples} samples; predicted {$result['predictions']} products.");
        return self::SUCCESS;
    }
}
