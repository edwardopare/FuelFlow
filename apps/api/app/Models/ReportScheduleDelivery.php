<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'report_schedule_id',
    'scheduled_for',
    'recipient',
    'status',
    'attempts',
    'attempted_at',
    'sent_at',
    'last_error',
])]
class ReportScheduleDelivery extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'attempts' => 'integer',
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function reportSchedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class);
    }
}
