<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAlertState extends Model
{
    protected $fillable = ['product_id', 'severity', 'last_stock', 'last_checked_at', 'last_notified_at', 'pending_delivery_id'];

    protected function casts(): array
    {
        return ['last_checked_at' => 'datetime', 'last_notified_at' => 'datetime'];
    }
}
