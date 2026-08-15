<?php

namespace App\Services;

use App\Enums\OrganizationStatus;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\VendorUser;
use Illuminate\Support\Facades\DB;

class TenantProvisioningService
{
    private const ORGANIZATION_ROLES = ['administrator', 'owner', 'accountant', 'auditor'];

    public function __construct(
        private readonly VendorAuditService $audit,
        private readonly OrganizationLicenseService $licenses,
        private readonly ApplicationNotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $companyData
     * @param  array<string, mixed>  $stationData
     * @param  array{duration: int, unit: string}  $licenseData
     * @param  list<array<string, mixed>>  $accounts
     */
    public function onboard(
        VendorUser $vendorUser,
        array $companyData,
        array $stationData,
        array $licenseData,
        array $accounts,
    ): Organization {
        $organization = DB::transaction(function () use (
            $vendorUser,
            $companyData,
            $stationData,
            $licenseData,
            $accounts,
        ): Organization {
            $timezone = $companyData['timezone'] ?? 'Africa/Accra';
            $organization = Organization::query()->create([
                ...$companyData,
                'status' => OrganizationStatus::Active,
                'currency' => 'GHS',
                'timezone' => $timezone,
                'onboarded_by_vendor_user_id' => $vendorUser->id,
                'activated_at' => now(),
                ...$this->licenses->terms(
                    (int) $licenseData['duration'],
                    $licenseData['unit'],
                ),
            ]);

            $headOffice = Station::query()->create([
                'organization_id' => $organization->id,
                'code' => 'HEAD-OFFICE',
                'station_number' => 'HO-0001',
                'name' => 'Head Office',
                'timezone' => $timezone,
                'phone' => $companyData['phone'] ?? null,
                'address' => $companyData['address'] ?? null,
                'is_active' => true,
            ]);

            $operatingStation = Station::query()->create([
                ...$stationData,
                'organization_id' => $organization->id,
                'timezone' => $timezone,
                'is_active' => true,
            ]);

            foreach ($accounts as $account) {
                $this->createAccount(
                    $organization,
                    $account,
                    in_array($account['role_slug'], self::ORGANIZATION_ROLES, true)
                        ? $headOffice
                        : $operatingStation,
                );
            }

            $this->audit->record(
                'vendor.organization.onboarded',
                $organization,
                after: [
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'status' => $organization->status->value,
                    'currency' => 'GHS',
                    'operating_station_id' => $operatingStation->id,
                    'accounts_created' => count($accounts),
                    'license_term_value' => $organization->license_term_value,
                    'license_term_unit' => $organization->license_term_unit,
                    'license_expires_at' => $organization->license_expires_at?->toIso8601String(),
                ],
                actor: $vendorUser,
            );

            return $organization;
        });

        $this->notifications->companyOnboarded($organization);

        return $organization;
    }

    /** @param array<string, mixed> $account */
    public function createAccount(
        Organization $organization,
        array $account,
        Station $station,
    ): User {
        $role = Role::query()->where('slug', $account['role_slug'])->firstOrFail();
        $isOrganizationRole = $role->scope === 'organization';

        if ($isOrganizationRole && $station->station_number !== 'HO-0001') {
            $station = $organization->stations()->where('station_number', 'HO-0001')->firstOrFail();
        }

        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => $account['name'],
            'email' => strtolower($account['email']),
            'phone' => $account['phone'] ?? null,
            'password' => $account['password'],
            'status' => UserStatus::PendingFirstLogin,
            'must_change_password' => true,
            'email_verified_at' => now(),
        ]);

        $user->stations()->attach($station->id, ['is_primary' => true]);
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $organization->id,
            'station_id' => $isOrganizationRole ? null : $station->id,
        ]);

        return $user;
    }
}
