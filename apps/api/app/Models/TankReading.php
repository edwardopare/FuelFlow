<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tank_id',
    'recorded_by',
    'reading_litres',
    'book_stock_litres',
    'variance_litres',
    'source',
    'read_at',
    'notes',
])]
class TankReading extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'reading_litres' => 'decimal:3',
            'book_stock_litres' => 'decimal:3',
            'variance_litres' => 'decimal:3',
            'read_at' => 'datetime',
        ];
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(Tank::class);
    }
}
