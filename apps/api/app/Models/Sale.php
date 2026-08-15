<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'station_id',
    'shift_id',
    'attendant_id',
    'nozzle_id',
    'tank_id',
    'product_id',
    'receipt_number',
    'litres',
    'unit_price',
    'amount',
    'payment_method',
    'payment_reference',
    'status',
    'sold_at',
    'reversed_by',
    'reversed_at',
    'reversal_reason',
])]
class Sale extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'litres' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'sold_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function nozzle(): BelongsTo
    {
        return $this->belongsTo(Nozzle::class);
    }

    public function attendant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendant_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
