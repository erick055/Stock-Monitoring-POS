<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPrice extends Model
{
    protected $primaryKey = 'supplier_price_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'previous_price' => 'decimal:2',
            'effective_date' => 'date',
            'last_updated_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(SupplierPriceHistory::class, 'supplier_price_id', 'supplier_price_id');
    }
}
