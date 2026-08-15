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
    'name',
    'report_type',
    'frequency',
    'send_time',
    'day_of_week',
    'day_of_month',
    'recipients',
    'is_active',
    'last_sent_at',
    'created_by',
])]
class ReportSchedule extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'is_active' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ReportScheduleDelivery::class);
    }
}
