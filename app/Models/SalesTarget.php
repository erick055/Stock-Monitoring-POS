<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class SalesTarget extends Model
{
    protected $fillable = ['month', 'amount', 'updated_by'];

    public function setMonthAttribute($value): void
    {
        $this->attributes['month'] = CarbonImmutable::parse($value)->startOfMonth()->toDateString();
    }

    protected function casts(): array
    {
        return ['month' => 'immutable_date', 'amount' => 'decimal:2'];
    }
}
