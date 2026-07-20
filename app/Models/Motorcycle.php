<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Motorcycle extends Model
{
    use HasFactory;

    protected $primaryKey = 'motorcycle_id';

    protected $fillable = [
        'brand', 'model', 'year', 'engine', 'variant', 'specifications', 'features',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'specifications' => 'array',
            'features' => 'array',
        ];
    }

    public function compatibilities(): HasMany
    {
        return $this->hasMany(PartCompatibility::class, 'motorcycle_id', 'motorcycle_id');
    }

    public function getDisplayNameAttribute(): string
    {
        $variant = $this->variant ? " {$this->variant}" : '';

        return "{$this->brand} {$this->model}{$variant} ({$this->year}) - {$this->engine}";
    }
}
