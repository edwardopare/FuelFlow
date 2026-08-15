<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\VendorOrganizationResource;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorAuditEvent;
use Illuminate\Http\JsonResponse;

class VendorDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $recent = Organization::query()
            ->withCount('users')
            ->withCount(['stations as stations_count' => fn ($query) => $query->where('station_number', '!=', 'HO-0001')])
            ->latest()
            ->limit(6)
            ->get();

        return response()->json([
            'data' => [
                'metrics' => [
                    'companies' => Organization::query()->count(),
                    'active_companies' => Organization::query()
                        ->where('status', OrganizationStatus::Active)
                        ->where(fn ($query) => $query
                            ->whereNull('license_expires_at')
                            ->orWhere('license_expires_at', '>', now()))
                        ->count(),
                    'suspended_companies' => Organization::query()
                        ->where('status', OrganizationStatus::Suspended)
                        ->where(fn ($query) => $query
                            ->whereNull('license_expires_at')
                            ->orWhere('license_expires_at', '>', now()))
                        ->count(),
                    'expired_licenses' => Organization::query()
                        ->where('status', '!=', OrganizationStatus::LicenseDeactivated)
                        ->where('license_expires_at', '<=', now())
                        ->count(),
                    'deactivated_licenses' => Organization::query()
                        ->where('status', OrganizationStatus::LicenseDeactivated)
                        ->count(),
                    'licenses_expiring_soon' => Organization::query()
                        ->where('status', OrganizationStatus::Active)
                        ->whereBetween('license_expires_at', [now(), now()->addDays(30)])
                        ->count(),
                    'accounts' => User::query()->count(),
                    'operating_stations' => Station::query()->where('station_number', '!=', 'HO-0001')->count(),
                ],
                'recent_companies' => VendorOrganizationResource::collection($recent),
                'recent_activity' => VendorAuditEvent::query()
                    ->with('actor:id,name')
                    ->latest('created_at')
                    ->limit(8)
                    ->get()
                    ->map(fn (VendorAuditEvent $event) => [
                        'id' => $event->id,
                        'action' => $event->action,
                        'actor' => $event->actor ? [
                            'id' => $event->actor->id,
                            'name' => $event->actor->name,
                        ] : null,
                        'subject_id' => $event->subject_id,
                        'reason' => $event->reason,
                        'created_at' => $event->created_at?->toIso8601String(),
                    ])->values(),
            ],
        ]);
    }
}
