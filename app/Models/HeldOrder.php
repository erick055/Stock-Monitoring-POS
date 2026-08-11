<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HeldOrder extends Model
{
    protected $primaryKey = 'held_order_id';

    protected $fillable = [
        'staff_id',
        'completed_sale_id',
        'status',
        'held_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'held_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function completedSale(): BelongsTo
    {
        return $this->belongsTo(SalesTransaction::class, 'completed_sale_id', 'sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(HeldOrderItem::class, 'held_order_id', 'held_order_id');
    }
}
