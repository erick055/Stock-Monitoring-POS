<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeadStockMlPrediction extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['factors' => 'array', 'predicted_at' => 'datetime', 'stagnation_probability' => 'float']; }
}
