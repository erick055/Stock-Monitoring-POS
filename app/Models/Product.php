<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $primaryKey = 'product_id';

    protected $fillable = [
        'sku', 'name', 'category', 'unit_cost', 'unit_price',
        'current_stock', 'reorder_level', 'is_active',
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
