<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pump_id',
    'tank_id',
    'product_id',
    'code',
    'status',
    'current_meter_reading',
    'meter_maximum',
])]
class Nozzle extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'current_meter_reading' => 'decimal:3',
            'meter_maximum' => 'decimal:3',
        ];
    }

    public function pump(): BelongsTo
    {
        return $this->belongsTo(Pump::class);
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
