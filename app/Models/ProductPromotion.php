<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPromotion extends Model
{
    protected $primaryKey = 'product_promotion_id';

    protected $fillable = [
        'product_id', 'bundle_product_id', 'applied_by', 'action_type', 'discount_percent',
        'original_price', 'promotional_price', 'bundle_note', 'status',
        'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
            'original_price' => 'decimal:2',
            'promotional_price' => 'decimal:2',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function bundleProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bundle_product_id', 'product_id');
    }

    public function getActionLabelAttribute(): string
    {
        return match ($this->action_type) {
            'promo_bundle' => 'Promo Bundle',
            'clearance' => 'Clearance Sale',
            default => 'Discount',
        };
    }
}
