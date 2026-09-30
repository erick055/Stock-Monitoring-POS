<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeadStockMlModel extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['coefficients' => 'array', 'means' => 'array', 'scales' => 'array', 'trained_at' => 'datetime']; }
}
