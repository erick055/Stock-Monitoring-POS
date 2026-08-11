<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierPriceHistory extends Model
{
    protected $primaryKey = 'supplier_price_history_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }
}
