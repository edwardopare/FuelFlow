<?php

namespace App\Services;

use App\Models\VendorAuditEvent;
use App\Models\VendorUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VendorAuditService
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
        ?string $reason = null,
        ?array $metadata = null,
        ?VendorUser $actor = null,
    ): VendorAuditEvent {
        $actor ??= Auth::guard('vendor')->user();

        return VendorAuditEvent::query()->create([
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
