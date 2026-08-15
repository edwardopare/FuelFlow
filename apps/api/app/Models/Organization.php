<?php

namespace App\Models;

use App\Enums\OrganizationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'slug',
    'status',
    'registration_number',
    'contact_email',
    'phone',
    'address',
    'currency',
    'timezone',
    'license_term_value',
    'license_term_unit',
    'license_started_at',
    'license_expires_at',
    'license_deactivated_at',
    'license_deactivation_reason',
    'onboarded_by_vendor_user_id',
    'activated_at',
    'suspended_at',
])]
class Organization extends Model
{
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'license_term_value' => 'integer',
            'license_started_at' => 'datetime',
            'license_expires_at' => 'datetime',
            'license_deactivated_at' => 'datetime',
        ];
    }

    public function hasActiveLicense(): bool
    {
        return $this->status !== OrganizationStatus::LicenseDeactivated
            && ($this->license_expires_at === null || $this->license_expires_at->isFuture());
    }

    public function isAccessible(): bool
    {
        return $this->status === OrganizationStatus::Active
            && $this->hasActiveLicense();
    }

    public function accessStatus(): string
    {
        if ($this->status === OrganizationStatus::LicenseDeactivated) {
            return OrganizationStatus::LicenseDeactivated->value;
        }

        if ($this->license_expires_at?->isPast()) {
            return 'expired';
        }

        return $this->status->value;
    }

    public function onboardedBy(): BelongsTo
    {
        return $this->belongsTo(VendorUser::class, 'onboarded_by_vendor_user_id');
    }

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
