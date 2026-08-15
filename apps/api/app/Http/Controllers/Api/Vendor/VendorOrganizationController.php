<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\StoreVendorOrganizationRequest;
use App\Http\Requests\Vendor\StoreVendorOrganizationUserRequest;
use App\Http\Requests\Vendor\UpdateVendorOrganizationLicenseRequest;
use App\Http\Resources\VendorOrganizationResource;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use App\Services\ApplicationNotificationService;
use App\Services\OrganizationLicenseService;
use App\Services\TenantProvisioningService;
use App\Services\VendorAuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class VendorOrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Organization::query()
            ->withCount('users')
            ->withCount(['stations as stations_count' => fn ($query) => $query->where('station_number', '!=', 'HO-0001')])
            ->latest();

        $validatedFilters = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,suspended,expired,license_deactivated'],
        ]);

        if (isset($validatedFilters['status'])) {
            match ($validatedFilters['status']) {
                'active' => $query
                    ->where('status', OrganizationStatus::Active)
                    ->where(fn ($query) => $query
                        ->whereNull('license_expires_at')
                        ->orWhere('license_expires_at', '>', now())),
                'expired' => $query
                    ->where('status', '!=', OrganizationStatus::LicenseDeactivated)
                    ->where('license_expires_at', '<=', now()),
                'suspended' => $query
                    ->where('status', OrganizationStatus::Suspended)
                    ->where(fn ($query) => $query
                        ->whereNull('license_expires_at')
                        ->orWhere('license_expires_at', '>', now())),
                'license_deactivated' => $query->where('status', OrganizationStatus::LicenseDeactivated),
            };
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(fn (Builder $query) => $query
                ->where('name', 'like', $search)
                ->orWhere('slug', 'like', $search)
                ->orWhere('contact_email', 'like', $search)
                ->orWhere('registration_number', 'like', $search));
        }

        return VendorOrganizationResource::collection(
            $query->paginate(min(max($request->integer('per_page', 20), 1), 100)),
        );
    }

    public function store(
        StoreVendorOrganizationRequest $request,
        TenantProvisioningService $provisioning,
    ) {
        $organization = $provisioning->onboard(
            $request->user('vendor'),
            $request->validated('company'),
            $request->validated('station'),
            $request->validated('license'),
            $request->validated('accounts'),
        );

        return (new VendorOrganizationResource($this->loadOrganization($organization)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Organization $organization): VendorOrganizationResource
    {
        return new VendorOrganizationResource($this->loadOrganization($organization));
    }

    public function storeUser(
        StoreVendorOrganizationUserRequest $request,
        Organization $organization,
        TenantProvisioningService $provisioning,
        VendorAuditService $audit,
        ApplicationNotificationService $notifications,
    ) {
        $station = $request->filled('station_id')
            ? Station::query()
                ->where('organization_id', $organization->id)
                ->findOrFail($request->string('station_id')->toString())
            : $organization->stations()->where('station_number', 'HO-0001')->firstOrFail();

        $user = DB::transaction(function () use (
            $request,
            $organization,
            $provisioning,
            $station,
            $audit,
        ): User {
            $user = $provisioning->createAccount(
                $organization,
                $request->safe()->only(['name', 'email', 'phone', 'password', 'role_slug']),
                $station,
            );

            $audit->record(
                'vendor.organization.account_created',
                $organization,
                after: [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'role' => $request->string('role_slug')->toString(),
                    'station_id' => $station->id,
                ],
            );

            return $user;
        });
        $notifications->userOnboarded($user);

        return (new VendorOrganizationResource($this->loadOrganization($organization)))
            ->response()
            ->setStatusCode(201);
    }

    public function suspend(
        Request $request,
        Organization $organization,
        VendorAuditService $audit,
    ): VendorOrganizationResource {
        abort_unless(
            $organization->status === OrganizationStatus::Active
                && $organization->hasActiveLicense(),
            422,
            'Only a company with an active license can be suspended.',
        );

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        DB::transaction(function () use ($organization, $validated, $audit): void {
            $before = ['status' => $organization->status->value];
            $organization->forceFill([
                'status' => OrganizationStatus::Suspended,
                'suspended_at' => now(),
            ])->save();

            $this->revokeTenantAccess($organization);

            $audit->record(
                'vendor.organization.suspended',
                $organization,
                before: $before,
                after: ['status' => OrganizationStatus::Suspended->value],
                reason: $validated['reason'],
            );
        });

        return new VendorOrganizationResource($this->loadOrganization($organization));
    }

    public function activate(
        Organization $organization,
        VendorAuditService $audit,
    ): VendorOrganizationResource {
        abort_unless(
            $organization->status === OrganizationStatus::Suspended
                && $organization->hasActiveLicense(),
            422,
            'Renew the company license before reactivating access.',
        );

        DB::transaction(function () use ($organization, $audit): void {
            $before = ['status' => $organization->status->value];
            $organization->forceFill([
                'status' => OrganizationStatus::Active,
                'activated_at' => now(),
                'suspended_at' => null,
            ])->save();

            $audit->record(
                'vendor.organization.activated',
                $organization,
                before: $before,
                after: ['status' => OrganizationStatus::Active->value],
            );
        });

        return new VendorOrganizationResource($this->loadOrganization($organization));
    }

    public function updateLicense(
        UpdateVendorOrganizationLicenseRequest $request,
        Organization $organization,
        OrganizationLicenseService $licenses,
        VendorAuditService $audit,
    ): VendorOrganizationResource {
        DB::transaction(function () use ($request, $organization, $licenses, $audit): void {
            $before = $this->licenseSnapshot($organization);
            $licenses->activate(
                $organization,
                $request->integer('duration'),
                $request->string('unit')->toString(),
            );

            $audit->record(
                'vendor.organization.license_updated',
                $organization,
                before: $before,
                after: $this->licenseSnapshot($organization->fresh()),
            );
        });

        return new VendorOrganizationResource($this->loadOrganization($organization));
    }

    public function deactivateLicense(
        Request $request,
        Organization $organization,
        VendorAuditService $audit,
    ): VendorOrganizationResource {
        abort_if(
            $organization->status === OrganizationStatus::LicenseDeactivated,
            422,
            'This company license is already deactivated.',
        );

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        DB::transaction(function () use ($organization, $validated, $audit): void {
            $before = $this->licenseSnapshot($organization);
            $organization->forceFill([
                'status' => OrganizationStatus::LicenseDeactivated,
                'license_deactivated_at' => now(),
                'license_deactivation_reason' => $validated['reason'],
                'suspended_at' => null,
            ])->save();
            $this->revokeTenantAccess($organization);

            $audit->record(
                'vendor.organization.license_deactivated',
                $organization,
                before: $before,
                after: $this->licenseSnapshot($organization),
                reason: $validated['reason'],
            );
        });

        return new VendorOrganizationResource($this->loadOrganization($organization));
    }

    private function loadOrganization(Organization $organization): Organization
    {
        return $organization->fresh()
            ->loadCount('users')
            ->loadCount(['stations as stations_count' => fn ($query) => $query->where('station_number', '!=', 'HO-0001')])
            ->load([
                'stations' => fn ($query) => $query->orderBy('name'),
                'users' => fn ($query) => $query
                    ->with(['stations', 'roleAssignments.role'])
                    ->orderBy('name'),
            ]);
    }

    private function revokeTenantAccess(Organization $organization): void
    {
        $userIds = $organization->users()->pluck('id');
        DB::table('sessions')->whereIn('user_id', $userIds)->delete();
        DB::table('personal_access_tokens')
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $userIds)
            ->delete();
    }

    /** @return array<string, mixed> */
    private function licenseSnapshot(Organization $organization): array
    {
        return [
            'status' => $organization->accessStatus(),
            'duration' => $organization->license_term_value,
            'unit' => $organization->license_term_unit,
            'started_at' => $organization->license_started_at?->toIso8601String(),
            'expires_at' => $organization->license_expires_at?->toIso8601String(),
            'deactivated_at' => $organization->license_deactivated_at?->toIso8601String(),
        ];
    }
}
