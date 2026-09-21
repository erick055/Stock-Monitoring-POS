<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierImport extends Model
{
    protected $primaryKey = 'supplier_import_id';

    protected $fillable = [
        'supplier_id', 'source_filename', 'file_hash', 'status', 'row_count', 'valid_count',
        'error_count', 'uploaded_by', 'approved_by', 'approved_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(SupplierImportRow::class, 'supplier_import_id', 'supplier_import_id');
    }
}
