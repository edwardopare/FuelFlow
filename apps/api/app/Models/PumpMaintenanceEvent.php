<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pump_id', 'status', 'description', 'started_at', 'completed_at', 'recorded_by'])]
class PumpMaintenanceEvent extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function pump(): BelongsTo
    {
        return $this->belongsTo(Pump::class);
    }
}
