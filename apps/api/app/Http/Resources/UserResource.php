<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status->value,
            'must_change_password' => $this->must_change_password,
            'organization' => [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
                'currency' => 'GHS',
                'timezone' => $this->organization->timezone,
            ],
            'stations' => StationResource::collection($this->whenLoaded('stations')),
            'role_assignments' => $this->whenLoaded(
                'roleAssignments',
                fn () => $this->roleAssignments->map(fn ($assignment) => [
                    'id' => $assignment->id,
                    'role' => [
                        'id' => $assignment->role->id,
                        'name' => $assignment->role->name,
                        'slug' => $assignment->role->slug,
                        'scope' => $assignment->role->scope,
                    ],
                    'station_id' => $assignment->station_id,
                ])->values(),
            ),
            'last_authenticated_at' => $this->last_authenticated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
