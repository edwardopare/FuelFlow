<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'station_id', 'code', 'name', 'status'])]
class Pump extends Model
{
    use HasUlids;

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function nozzles(): HasMany
    {
        return $this->hasMany(Nozzle::class);
    }

    public function maintenanceEvents(): HasMany
    {
        return $this->hasMany(PumpMaintenanceEvent::class);
    }
}
