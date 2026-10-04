<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

class RefreshInventoryPredictions implements ShouldQueue
{
    use Queueable;

    public $timeout = 1800;
    public $tries = 1;

    public function handle(): void
    {
        Artisan::call('ml:refresh');
    }
}
