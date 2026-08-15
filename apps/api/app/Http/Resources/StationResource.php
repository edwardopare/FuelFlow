<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canViewPeople = $request->user()?->hasPermission('users.view') === true
            || $request->user()?->hasPermission('stations.manage') === true;
        $users = $canViewPeople && $this->relationLoaded('users')
            ? $this->users
            : collect();
        $managers = $users->filter(
            fn ($user) => $user->roleAssignments->contains(
                fn ($assignment) => $assignment->station_id === $this->id
                    && $assignment->role?->slug === 'station_manager',
            ),
        );

        return [
            'id' => $this->id,
            'code' => $this->code,
            'station_number' => $this->station_number,
            'name' => $this->name,
            'registration_number' => $this->registration_number,
            'phone' => $this->phone,
            'address' => $this->address,
            'timezone' => $this->timezone,
            'currency' => 'GHS',
            'is_active' => $this->is_active,
            'manager' => $canViewPeople ? $managers->map(fn ($manager) => [
                'id' => $manager->id,
                'name' => $manager->name,
                'email' => $manager->email,
                'phone' => $manager->phone,
                'status' => $manager->status->value,
            ])->first() : null,
            'assigned_users' => $canViewPeople ? $users->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status->value,
                'roles' => $user->roleAssignments
                    ->filter(
                        fn ($assignment) => $assignment->station_id === $this->id
                            || $assignment->station_id === null,
                    )
                    ->pluck('role.name')
                    ->unique()
                    ->values(),
            ])->sortBy('name')->values() : [],
            'assigned_users_count' => $canViewPeople ? $users->count() : null,
        ];
    }
}
