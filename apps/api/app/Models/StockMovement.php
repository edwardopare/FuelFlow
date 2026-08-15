<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'station_id',
    'tank_id',
    'product_id',
    'type',
    'quantity_litres',
    'balance_after_litres',
    'source_type',
    'source_id',
    'recorded_by',
    'occurred_at',
    'notes',
])]
class StockMovement extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'quantity_litres' => 'decimal:3',
            'balance_after_litres' => 'decimal:3',
            'occurred_at' => 'datetime',
        ];
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(Tank::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
