<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'station_id',
    'product_id',
    'code',
    'name',
    'tank_grade',
    'capacity_litres',
    'book_stock_litres',
    'minimum_safe_litres',
    'maximum_safe_litres',
    'atg_enabled',
    'status',
])]
class Tank extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'capacity_litres' => 'decimal:3',
            'book_stock_litres' => 'decimal:3',
            'minimum_safe_litres' => 'decimal:3',
            'maximum_safe_litres' => 'decimal:3',
            'atg_enabled' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(TankReading::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
