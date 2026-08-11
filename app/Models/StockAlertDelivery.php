<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAlertDelivery extends Model
{
    protected $fillable = [
        'product_id', 'channel', 'alert_type', 'status', 'recipient',
        'message', 'error', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
