<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReturn extends Model
{
    use HasFactory;

    protected $primaryKey = 'return_id';

    protected $fillable = [
        'product_id',
        'sale_id',
        'user_id',
        'quantity',
        'reason',
        'item_condition',
        'refund_amount',
        'status',
        'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'refund_amount' => 'decimal:2',
            'returned_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(SalesTransaction::class, 'sale_id', 'sale_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
