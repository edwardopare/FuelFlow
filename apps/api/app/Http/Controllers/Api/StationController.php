<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StationResource;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Station::query()->orderBy('name');

        if ($user->hasPermission('users.view')
            || $user->hasPermission('stations.manage')) {
            $query->with(['users.roleAssignments.role']);
        }

        if ($user->hasOrganizationWidePermission('stations.view')) {
            $query->where('organization_id', $user->organization_id);
        } else {
            $query->whereIn('id', $user->stations()->pluck('stations.id'));
        }

        return StationResource::collection($query->get());
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $this->requirePermission($request, 'stations.manage');
        $organizationId = $request->user()->organization_id;
        $validated = $request->validate($this->rules($organizationId));

        $station = DB::transaction(function () use (
            $validated,
            $organizationId,
            $audit,
        ): Station {
            $managerId = $validated['manager_user_id'] ?? null;
            unset($validated['manager_user_id']);
            $station = Station::query()->create([
                ...$validated,
                'organization_id' => $organizationId,
                'is_active' => true,
            ]);
            $this->assignManager($station, $managerId);
            $audit->record(
                'station.created',
                $station,
                after: $station->toArray(),
                stationId: $station->id,
            );

            return $station;
        });

        return (new StationResource(
            $station->load(['users.roleAssignments.role']),
        ))->response()->setStatusCode(201);
    }

    public function update(
        Request $request,
        Station $station,
        AuditService $audit,
    ): StationResource {
        $this->assertOrganization($request, $station);
        $this->requirePermission($request, 'stations.manage');
        $validated = $request->validate($this->rules(
            $station->organization_id,
            $station->id,
        ));

        DB::transaction(function () use ($station, $validated, $audit): void {
            $before = $station->toArray();
            $managerId = $validated['manager_user_id'] ?? null;
            unset($validated['manager_user_id']);
            $station->update($validated);
            $this->assignManager($station, $managerId);
            $audit->record(
                'station.updated',
                $station,
                before: $before,
                after: $station->fresh()->toArray(),
                stationId: $station->id,
            );
        });

        return new StationResource(
            $station->load(['users.roleAssignments.role']),
        );
    }

    public function deactivate(
        Request $request,
        Station $station,
        AuditService $audit,
    ): StationResource {
        $this->assertOrganization($request, $station);
        $this->requirePermission($request, 'stations.manage');
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $station->update(['is_active' => false]);
        $audit->record(
            'station.deactivated',
            $station,
            after: ['is_active' => false],
            stationId: $station->id,
            reason: $validated['reason'],
        );

        return new StationResource(
            $station->load(['users.roleAssignments.role']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(string $organizationId, ?string $stationId = null): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('stations', 'code')
                    ->where('organization_id', $organizationId)
                    ->ignore($stationId),
            ],
            'station_number' => [
                'required',
                'string',
                'max:40',
                Rule::unique('stations', 'station_number')
                    ->where('organization_id', $organizationId)
                    ->ignore($stationId),
            ],
            'name' => ['required', 'string', 'max:160'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:1000'],
            'timezone' => ['required', 'timezone'],
            'manager_user_id' => [
                'nullable',
                Rule::exists('users', 'id')
                    ->where('organization_id', $organizationId),
            ],
        ];
    }

    private function assignManager(Station $station, ?string $managerId): void
    {
        if (! $managerId) {
            return;
        }

        $manager = User::query()
            ->where('organization_id', $station->organization_id)
            ->findOrFail($managerId);
        $role = Role::query()->where('slug', 'station_manager')->firstOrFail();

        UserRoleAssignment::query()
            ->where('station_id', $station->id)
            ->where('role_id', $role->id)
            ->delete();
        $manager->stations()->syncWithoutDetaching([
            $station->id => ['is_primary' => $manager->stations()->count() === 0],
        ]);
        $manager->roleAssignments()->create([
            'role_id' => $role->id,
            'organization_id' => $station->organization_id,
            'station_id' => $station->id,
        ]);
    }

    private function assertOrganization(Request $request, Station $station): void
    {
        abort_unless(
            $station->organization_id === $request->user()->organization_id,
            404,
        );
    }
}
