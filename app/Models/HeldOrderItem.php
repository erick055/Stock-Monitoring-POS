<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HeldOrderItem extends Model
{
    protected $primaryKey = 'held_order_item_id';

    protected $fillable = [
        'held_order_id',
        'product_id',
        'quantity',
        'unit_price',
    ];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2'];
    }

    public function heldOrder(): BelongsTo
    {
        return $this->belongsTo(HeldOrder::class, 'held_order_id', 'held_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
