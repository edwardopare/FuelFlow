<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorOrganizationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->accessStatus(),
            'registration_number' => $this->registration_number,
            'contact_email' => $this->contact_email,
            'phone' => $this->phone,
            'address' => $this->address,
            'currency' => 'GHS',
            'timezone' => $this->timezone,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'license' => [
                'status' => $this->accessStatus() === 'suspended'
                    ? ($this->hasActiveLicense() ? 'active' : $this->accessStatus())
                    : $this->accessStatus(),
                'duration' => $this->license_term_value,
                'unit' => $this->license_term_unit,
                'started_at' => $this->license_started_at?->toIso8601String(),
                'expires_at' => $this->license_expires_at?->toIso8601String(),
                'deactivated_at' => $this->license_deactivated_at?->toIso8601String(),
                'deactivation_reason' => $this->license_deactivation_reason,
                'remaining_days' => $this->license_expires_at
                    ? max(0, (int) now()->startOfDay()->diffInDays(
                        $this->license_expires_at->copy()->startOfDay(),
                        false,
                    ))
                    : null,
            ],
            'users_count' => $this->whenCounted('users'),
            'stations_count' => $this->getAttribute('stations_count'),
            'stations' => $this->whenLoaded('stations', fn () => $this->stations->map(fn ($station) => [
                'id' => $station->id,
                'name' => $station->name,
                'code' => $station->code,
                'station_number' => $station->station_number,
                'phone' => $station->phone,
                'address' => $station->address,
                'is_active' => $station->is_active,
            ])->values()),
            'accounts' => $this->whenLoaded('users', fn () => $this->users->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status->value,
                'must_change_password' => $user->must_change_password,
                'station' => $user->stations->first() ? [
                    'id' => $user->stations->first()->id,
                    'name' => $user->stations->first()->name,
                ] : null,
                'role' => $user->roleAssignments->first()?->role ? [
                    'slug' => $user->roleAssignments->first()->role->slug,
                    'name' => $user->roleAssignments->first()->role->name,
                    'scope' => $user->roleAssignments->first()->role->scope,
                ] : null,
                'created_at' => $user->created_at?->toIso8601String(),
            ])->sortBy('name')->values()),
        ];
    }
}
