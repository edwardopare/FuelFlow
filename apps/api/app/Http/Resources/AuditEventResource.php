<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ] : null,
            'station_id' => $this->station_id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'before' => $this->before,
            'after' => $this->after,
            'metadata' => $this->metadata,
            'reason' => $this->reason,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
