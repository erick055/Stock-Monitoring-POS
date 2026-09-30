<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAlertSetting extends Model
{
    protected $fillable = [
        'email_enabled', 'daily_summary_enabled',
        'notification_email', 'daily_summary_time',
        'last_daily_summary_at',
    ];

    protected function casts(): array
    {
        return [
            'email_enabled' => 'boolean',
            'daily_summary_enabled' => 'boolean',
            'last_daily_summary_at' => 'datetime',
        ];
    }
}
