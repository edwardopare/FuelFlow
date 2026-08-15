<?php

namespace App\Models;

use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'organization_id',
    'name',
    'email',
    'phone',
    'status',
    'must_change_password',
    'terminal_pin',
    'email_verified_at',
    'last_authenticated_at',
    'deactivated_at',
    'password',
])]
#[Hidden(['password', 'terminal_pin', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_authenticated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'must_change_password' => 'boolean',
            'status' => UserStatus::class,
            'password' => 'hashed',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function stations(): BelongsToMany
    {
        return $this->belongsToMany(Station::class, 'user_station_assignments')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    public function hasPermission(string $permission, ?string $stationId = null): bool
    {
        return $this->roleAssignments()
            ->where('organization_id', $this->organization_id)
            ->when(
                $stationId,
                fn ($query) => $query->where(
                    fn ($scope) => $scope
                        ->whereNull('station_id')
                        ->orWhere('station_id', $stationId),
                ),
            )
            ->whereHas(
                'role.permissions',
                fn ($query) => $query->where('name', $permission),
            )
            ->exists();
    }

    public function hasOrganizationWidePermission(string $permission): bool
    {
        return $this->roleAssignments()
            ->where('organization_id', $this->organization_id)
            ->whereNull('station_id')
            ->whereHas(
                'role.permissions',
                fn ($query) => $query->where('name', $permission),
            )
            ->exists();
    }
}
