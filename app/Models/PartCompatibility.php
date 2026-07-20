<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartCompatibility extends Model
{
    use HasFactory;

    protected $primaryKey = 'compatibility_id';

    protected $fillable = [
        'product_id', 'motorcycle_id', 'compatibility_status', 'fitment_notes',
        'reasons', 'conditions', 'source_reference', 'verified_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'conditions' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function motorcycle(): BelongsTo
    {
        return $this->belongsTo(Motorcycle::class, 'motorcycle_id', 'motorcycle_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
