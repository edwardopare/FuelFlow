<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Station $stationOne;

    private Station $stationTwo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->organization = Organization::factory()->create();
        $this->stationOne = Station::factory()->for($this->organization)->create();
        $this->stationTwo = Station::factory()->for($this->organization)->create();
    }

    public function test_administrator_can_create_station_scoped_user_with_audit_event(): void
    {
        $administrator = $this->userWithRole('administrator', null);
        $attendantRole = Role::query()->where('slug', 'cashier_attendant')->firstOrFail();
        Sanctum::actingAs($administrator);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Ama Mensah',
            'email' => 'ama.mensah@example.com',
            'phone' => '+233200000001',
            'password' => 'ChangeMeNow1!',
            'password_confirmation' => 'ChangeMeNow1!',
            'station_ids' => [$this->stationOne->id],
            'role_assignments' => [[
                'role_id' => $attendantRole->id,
                'station_id' => $this->stationOne->id,
            ]],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_first_login')
            ->assertJsonPath('data.organization.currency', 'GHS')
            ->assertJsonPath('data.stations.0.id', $this->stationOne->id)
            ->assertJsonPath(
                'data.role_assignments.0.role.slug',
                'cashier_attendant',
            );

        $createdId = $response->json('data.id');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.created',
            'subject_id' => $createdId,
            'station_id' => $this->stationOne->id,
            'actor_id' => $administrator->id,
        ]);
    }

    public function test_station_manager_only_lists_attendants_from_assigned_station(): void
    {
        $manager = $this->userWithRole('station_manager', $this->stationOne);
        $visible = $this->userWithRole('cashier_attendant', $this->stationOne);
        $hidden = $this->userWithRole('cashier_attendant', $this->stationTwo);
        $owner = $this->userWithRole('owner', $this->stationOne);
        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/users')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($manager->id));
        $this->assertFalse($ids->contains($hidden->id));
        $this->assertFalse($ids->contains($owner->id));
    }

    public function test_station_manager_can_only_view_assigned_station_attendant_details(): void
    {
        $manager = $this->userWithRole('station_manager', $this->stationOne);
        $attendant = $this->userWithRole('cashier_attendant', $this->stationOne);
        $owner = $this->userWithRole('owner', $this->stationOne);
        $otherStationAttendant = $this->userWithRole(
            'cashier_attendant',
            $this->stationTwo,
        );
        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/users/{$attendant->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $attendant->id);
        $this->getJson("/api/v1/users/{$owner->id}")->assertNotFound();
        $this->getJson("/api/v1/users/{$manager->id}")->assertNotFound();
        $this->getJson("/api/v1/users/{$otherStationAttendant->id}")->assertNotFound();
    }

    public function test_only_administrator_can_view_audit_events(): void
    {
        $administrator = $this->userWithRole('administrator', null);
        $stationManager = $this->userWithRole(
            'station_manager',
            $this->stationOne,
        );
        $auditor = $this->userWithRole('auditor', null);
        $event = AuditEvent::query()->create([
            'organization_id' => $this->organization->id,
            'actor_id' => $administrator->id,
            'action' => 'access.restricted',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($stationManager);
        $this->getJson('/api/v1/audit-events')->assertForbidden();

        Sanctum::actingAs($auditor);
        $this->getJson('/api/v1/audit-events')->assertForbidden();

        Sanctum::actingAs($administrator);
        $this->getJson('/api/v1/audit-events')
            ->assertOk()
            ->assertJsonPath('data.0.id', $event->id);
    }

    public function test_auditor_cannot_create_user(): void
    {
        $auditor = $this->userWithRole('auditor', null);
        $attendantRole = Role::query()->where('slug', 'cashier_attendant')->firstOrFail();
        Sanctum::actingAs($auditor);

        $this->postJson('/api/v1/users', [
            'name' => 'Read Only',
            'email' => 'readonly@example.com',
            'password' => 'ChangeMeNow1!',
            'password_confirmation' => 'ChangeMeNow1!',
            'station_ids' => [$this->stationOne->id],
            'role_assignments' => [[
                'role_id' => $attendantRole->id,
                'station_id' => $this->stationOne->id,
            ]],
        ])->assertForbidden();
    }

    public function test_administrator_can_deactivate_user_and_revoke_access(): void
    {
        $administrator = $this->userWithRole('administrator', null);
        $attendant = $this->userWithRole('cashier_attendant', $this->stationOne);
        Sanctum::actingAs($administrator);

        $this->postJson("/api/v1/users/{$attendant->id}/deactivate", [
            'reason' => 'Employment at the station has ended.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.deactivated',
            'subject_id' => $attendant->id,
            'actor_id' => $administrator->id,
        ]);
    }

    public function test_user_with_activity_cannot_be_hard_deleted(): void
    {
        $administrator = $this->userWithRole('administrator', null);
        $attendant = $this->userWithRole('cashier_attendant', $this->stationOne);
        $attendant->forceFill(['last_authenticated_at' => now()])->save();
        Sanctum::actingAs($administrator);

        $this->deleteJson("/api/v1/users/{$attendant->id}", [
            'reason' => 'Requested cleanup of an active historical account.',
        ])->assertConflict();

        $this->assertNotNull(User::query()->find($attendant->id));
    }

    public function test_audit_event_cannot_be_updated_or_deleted_through_model(): void
    {
        $administrator = $this->userWithRole('administrator', null);
        $event = AuditEvent::query()->create([
            'organization_id' => $this->organization->id,
            'actor_id' => $administrator->id,
            'action' => 'test.event',
            'created_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $event->update(['action' => 'test.changed']);
    }

    private function userWithRole(string $roleSlug, ?Station $station): User
    {
        $user = User::factory()
            ->for($this->organization)
            ->create(['status' => UserStatus::Active]);
        $assignedStation = $station ?? $this->stationOne;
        $user->stations()->attach($assignedStation, ['is_primary' => true]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roleAssignments()->create([
            'role_id' => $role->id,
            'organization_id' => $this->organization->id,
            'station_id' => $role->scope === 'station' ? $assignedStation->id : null,
        ]);

        return $user;
    }
}
