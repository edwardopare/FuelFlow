<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?string $stationId = null,
        ?string $reason = null,
        ?array $metadata = null,
        ?User $actor = null,
    ): AuditEvent {
        $actor ??= Auth::user();

        return AuditEvent::query()->create([
            'organization_id' => $actor?->organization_id
                ?? $subject?->getAttribute('organization_id'),
            'station_id' => $stationId ?? $subject?->getAttribute('station_id'),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'metadata' => $metadata,
            'reason' => $reason,
            'request_id' => $this->request->attributes->get('request_id'),
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'created_at' => now(),
        ]);
    }
}
