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
    'attendant_id',
    'pump_id',
    'code',
    'recurrence_id',
    'status',
    'scheduled_start',
    'scheduled_end',
    'opened_at',
    'started_by',
    'late_seconds',
    'closed_at',
    'ended_by',
    'overtime_seconds',
    'expected_cash',
    'counted_cash',
    'notes',
])]
class Shift extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'late_seconds' => 'integer',
            'overtime_seconds' => 'integer',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
        ];
    }

    public function attendant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendant_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function pump(): BelongsTo
    {
        return $this->belongsTo(Pump::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function meterReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }
}
