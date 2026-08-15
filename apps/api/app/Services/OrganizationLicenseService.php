<?php

namespace App\Services;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use Carbon\CarbonImmutable;

class OrganizationLicenseService
{
    /** @return array<string, mixed> */
    public function terms(int $duration, string $unit): array
    {
        $startedAt = CarbonImmutable::now();
        $expiresAt = $unit === 'months'
            ? $startedAt->addMonthsNoOverflow($duration)
            : $startedAt->addYearsNoOverflow($duration);

        return [
            'license_term_value' => $duration,
            'license_term_unit' => $unit,
            'license_started_at' => $startedAt,
            'license_expires_at' => $expiresAt,
            'license_deactivated_at' => null,
            'license_deactivation_reason' => null,
        ];
    }

    public function activate(Organization $organization, int $duration, string $unit): void
    {
        $organization->forceFill([
            ...$this->terms($duration, $unit),
            'status' => OrganizationStatus::Active,
            'activated_at' => now(),
            'suspended_at' => null,
        ])->save();
    }
}
