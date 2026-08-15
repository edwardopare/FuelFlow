<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'actor_id',
    'action',
    'subject_type',
    'subject_id',
    'before',
    'after',
    'metadata',
    'reason',
    'request_id',
    'ip_address',
    'user_agent',
    'created_at',
])]
class VendorAuditEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Vendor audit events are immutable.'));
        static::deleting(fn () => throw new LogicException('Vendor audit events are immutable.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(VendorUser::class, 'actor_id');
    }
}
