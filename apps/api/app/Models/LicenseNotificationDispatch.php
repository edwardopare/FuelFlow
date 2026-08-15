<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'license_expires_at',
    'days_before_expiry',
    'recipients',
    'dispatched_at',
])]
class LicenseNotificationDispatch extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'license_expires_at' => 'datetime',
            'days_before_expiry' => 'integer',
            'recipients' => 'array',
            'dispatched_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
