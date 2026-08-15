<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shift_id', 'nozzle_id', 'type', 'reading', 'recorded_by', 'recorded_at'])]
class MeterReading extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'reading' => 'decimal:3',
            'recorded_at' => 'datetime',
        ];
    }

    public function nozzle(): BelongsTo
    {
        return $this->belongsTo(Nozzle::class);
    }
}
