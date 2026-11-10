<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesTarget extends Model
{
    protected $fillable = ['month', 'amount', 'updated_by'];

    public function setMonthAttribute($value): void
    {
        $this->attributes['month'] = \Carbon\CarbonImmutable::parse($value)->startOfMonth()->toDateString();
    }

    protected function casts(): array
    {
        return ['month' => 'immutable_date', 'amount' => 'decimal:2'];
    }
}
