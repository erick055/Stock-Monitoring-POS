<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesTransaction extends Model
{
    use HasFactory;

    protected $primaryKey = 'sale_id';

    protected $fillable = [
        'staff_id',
        'customer_id',
        'subtotal',
        'tax_amount',
        'total_sale_amount',
        'payment_status',
        'payment_method',
        'sale_date',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_sale_amount' => 'decimal:2',
            'sale_date' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesItem::class, 'sale_id', 'sale_id');
    }
}
