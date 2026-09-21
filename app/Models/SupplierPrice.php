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
            'auto_match_disabled' => 'boolean',
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

    public function comparisonWithProduct(): ?array
    {
        if (! $this->product) {
            return null;
        }

        $supplierCents = (int) round((float) $this->unit_price * 100);
        $productCostCents = (int) round((float) $this->product->unit_cost * 100);
        $sellingPriceCents = (int) round((float) $this->product->unit_price * 100);
        $isPeso = strtoupper(trim((string) $this->currency)) === 'PHP';

        return [
            'supplier_cents' => $supplierCents,
            'product_cost_cents' => $productCostCents,
            'selling_price_cents' => $sellingPriceCents,
            'cost_difference_cents' => $isPeso ? $supplierCents - $productCostCents : null,
            'projected_profit_cents' => $isPeso ? $sellingPriceCents - $supplierCents : null,
            'is_comparable_currency' => $isPeso,
        ];
    }
}
