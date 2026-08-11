<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory;

    protected $primaryKey = 'product_id';

    protected $fillable = [
        'sku', 'name', 'manufacturer', 'manufacturer_part_number', 'description',
        'category', 'unit_cost', 'unit_price', 'current_stock',
        'reorder_level', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(InventoryLedger::class, 'product_id', 'product_id');
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SalesItem::class, 'product_id', 'product_id');
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(ProductPromotion::class, 'product_id', 'product_id');
    }

    public function activePromotion(): HasOne
    {
        return $this->hasOne(ProductPromotion::class, 'product_id', 'product_id')
            ->where('status', 'active')
            ->latestOfMany('product_promotion_id');
    }

    public function getSellingPriceAttribute(): float
    {
        return (float) ($this->activePromotion?->promotional_price ?? $this->unit_price);
    }

    public function getStockStatusAttribute(): string
    {
        if ($this->current_stock <= $this->reorder_level) {
            return 'critical';
        }

        if ($this->current_stock <= ($this->reorder_level * 2)) {
            return 'warning';
        }

        return 'healthy';
    }
}
