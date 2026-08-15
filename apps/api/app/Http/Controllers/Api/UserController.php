<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();
        $query = User::query()
            ->where('organization_id', $actor->organization_id)
            ->with(['organization', 'stations', 'roleAssignments.role'])
            ->orderBy('name');

        if (! $actor->hasOrganizationWidePermission('users.view')) {
            $stationIds = $actor->stations()->pluck('stations.id');
            $query->whereHas(
                'stations',
                fn (Builder $query) => $query->whereIn('stations.id', $stationIds),
            );

            if ($this->isStationManager($actor)) {
                $query->whereHas(
                    'roleAssignments',
                    fn (Builder $query) => $query
                        ->whereIn('station_id', $stationIds)
                        ->whereHas(
                            'role',
                            fn (Builder $query) => $query
                                ->where('slug', 'cashier_attendant'),
                        ),
                );
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(
                fn (Builder $query) => $query
                    ->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search),
            );
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return UserResource::collection($query->paginate($perPage));
    }

    public function store(
        StoreUserRequest $request,
        AuditService $audit,
    ): JsonResponse {
        $user = DB::transaction(function () use ($request, $audit): User {
            $user = User::query()->create([
                'organization_id' => $request->user()->organization_id,
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->lower()->toString(),
                'phone' => $request->input('phone'),
                'password' => $request->string('password')->toString(),
                'status' => UserStatus::PendingFirstLogin,
                'must_change_password' => true,
            ]);

            $stationSync = collect($request->input('station_ids'))
                ->mapWithKeys(
                    fn (string $stationId, int $index) => [
                        $stationId => ['is_primary' => $index === 0],
                    ],
                )
                ->all();
            $user->stations()->sync($stationSync);

            foreach ($request->input('role_assignments') as $assignment) {
                $user->roleAssignments()->create([
                    'organization_id' => $user->organization_id,
                    'role_id' => $assignment['role_id'],
                    'station_id' => $assignment['station_id'] ?? null,
                ]);
            }

            $audit->record(
                'user.created',
                $user,
                after: $this->auditSnapshot($user),
                stationId: $request->input('station_ids.0'),
            );

            return $user;
        });

        return (new UserResource(
            $user->load(['organization', 'stations', 'roleAssignments.role']),
        ))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        $this->assertVisibleTo(request()->user(), $user);

        return new UserResource(
            $user->load(['organization', 'stations', 'roleAssignments.role']),
        );
    }

    public function update(
        UpdateUserRequest $request,
        User $user,
        AuditService $audit,
    ): UserResource {
        DB::transaction(function () use ($request, $user, $audit): void {
            $user->load(['stations', 'roleAssignments.role']);
            $before = $this->auditSnapshot($user);
            $data = $request->safe()->only(['name', 'email', 'phone', 'password']);

            if (array_key_exists('email', $data)) {
                $data['email'] = strtolower($data['email']);
            }

            if (array_key_exists('password', $data)) {
                $data['must_change_password'] = true;
            }

            $user->fill($data)->save();

            if ($request->has('station_ids')) {
                $stationSync = collect($request->input('station_ids'))
                    ->mapWithKeys(
                        fn (string $stationId, int $index) => [
                            $stationId => ['is_primary' => $index === 0],
                        ],
                    )
                    ->all();
                $user->stations()->sync($stationSync);
                $user->roleAssignments()->delete();

                foreach ($request->input('role_assignments') as $assignment) {
                    $user->roleAssignments()->create([
                        'organization_id' => $user->organization_id,
                        'role_id' => $assignment['role_id'],
                        'station_id' => $assignment['station_id'] ?? null,
                    ]);
                }
            }

            $user->load(['stations', 'roleAssignments.role']);
            $audit->record(
                'user.updated',
                $user,
                before: $before,
                after: $this->auditSnapshot($user),
                stationId: $user->stations()->value('stations.id'),
            );
        });

        return new UserResource(
            $user->load(['organization', 'stations', 'roleAssignments.role']),
        );
    }

    public function deactivate(
        Request $request,
        User $user,
        AuditService $audit,
    ): UserResource {
        abort_if($request->user()->is($user), 422, 'You cannot deactivate yourself.');
        abort_unless(
            $request->user()->hasOrganizationWidePermission('users.manage')
                && $request->user()->organization_id === $user->organization_id,
            403,
        );

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        DB::transaction(function () use ($user, $audit, $validated): void {
            $before = $this->auditSnapshot($user);
            $user->forceFill([
                'status' => UserStatus::Inactive,
                'deactivated_at' => now(),
            ])->save();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
            $audit->record(
                'user.deactivated',
                $user,
                before: $before,
                after: $this->auditSnapshot($user),
                stationId: $user->stations()->value('stations.id'),
                reason: $validated['reason'],
            );
        });

        return new UserResource(
            $user->load(['organization', 'stations', 'roleAssignments.role']),
        );
    }

    public function activate(
        Request $request,
        User $user,
        AuditService $audit,
    ): UserResource {
        abort_unless(
            $request->user()->hasOrganizationWidePermission('users.manage')
                && $request->user()->organization_id === $user->organization_id,
            403,
        );

        DB::transaction(function () use ($user, $audit): void {
            $before = $this->auditSnapshot($user);
            $user->forceFill([
                'status' => UserStatus::Active,
                'deactivated_at' => null,
            ])->save();
            $audit->record(
                'user.activated',
                $user,
                before: $before,
                after: $this->auditSnapshot($user),
                stationId: $user->stations()->value('stations.id'),
            );
        });

        return new UserResource(
            $user->load(['organization', 'stations', 'roleAssignments.role']),
        );
    }

    public function destroy(
        Request $request,
        User $user,
        AuditService $audit,
    ): JsonResponse {
        abort_if($request->user()->is($user), 422, 'You cannot delete yourself.');
        abort_unless(
            $request->user()->hasOrganizationWidePermission('users.manage')
                && $request->user()->organization_id === $user->organization_id,
            403,
        );

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $hasBusinessActivity = $user->last_authenticated_at !== null
            || AuditEvent::query()->where('actor_id', $user->getKey())->exists();

        abort_if(
            $hasBusinessActivity,
            409,
            'This user has activity and must be deactivated instead of deleted.',
        );

        DB::transaction(function () use ($user, $audit, $validated): void {
            $audit->record(
                'user.deleted',
                $user,
                before: $this->auditSnapshot($user),
                stationId: $user->stations()->value('stations.id'),
                reason: $validated['reason'],
            );
            $user->forceDelete();
        });

        return response()->json(status: 204);
    }

    private function assertVisibleTo(User $actor, User $target): void
    {
        abort_unless($actor->organization_id === $target->organization_id, 404);

        if ($actor->hasOrganizationWidePermission('users.view')) {
            return;
        }

        $actorStationIds = $actor->stations()->pluck('stations.id');
        abort_unless(
            $target->stations()->whereIn('stations.id', $actorStationIds)->exists(),
            404,
        );

        if ($this->isStationManager($actor)) {
            abort_unless(
                $target->roleAssignments()
                    ->whereIn('station_id', $actorStationIds)
                    ->whereHas(
                        'role',
                        fn (Builder $query) => $query->where('slug', 'cashier_attendant'),
                    )
                    ->exists(),
                404,
            );
        }
    }

    private function isStationManager(User $user): bool
    {
        return $user->roleAssignments()
            ->whereHas(
                'role',
                fn (Builder $query) => $query->where('slug', 'station_manager'),
            )
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status->value,
            'must_change_password' => $user->must_change_password,
            'station_ids' => $user->relationLoaded('stations')
                ? $user->stations->pluck('id')->all()
                : $user->stations()->pluck('stations.id')->all(),
            'roles' => $user->relationLoaded('roleAssignments')
                ? $user->roleAssignments->map(fn ($assignment) => [
                    'role_id' => $assignment->role_id,
                    'station_id' => $assignment->station_id,
                ])->all()
                : [],
        ];
    }
}
