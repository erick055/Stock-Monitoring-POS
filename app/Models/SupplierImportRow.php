<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierImportRow extends Model
{
    protected $primaryKey = 'supplier_import_row_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'effective_date' => 'date',
            'validation_errors' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(SupplierImport::class, 'supplier_import_id', 'supplier_import_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
