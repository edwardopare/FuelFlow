<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StationManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_head_office_contains_head_office_accounts(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $headOffice = Station::query()
            ->where('code', 'HO-001')
            ->firstOrFail();
        $accountantRole = Role::query()
            ->where('slug', 'accountant')
            ->firstOrFail();

        $this->assertSame('Head Office', $headOffice->name);
        $this->assertSame('organization', $accountantRole->scope);

        foreach ([
            'admin@fuelflow.local',
            'accountant@fuelflow.local',
            'owner@fuelflow.local',
        ] as $email) {
            $user = User::query()->where('email', $email)->firstOrFail();

            $this->assertDatabaseHas('user_station_assignments', [
                'user_id' => $user->id,
                'station_id' => $headOffice->id,
                'is_primary' => true,
            ]);
            $this->assertSame(1, $user->stations()->count());
        }

        $accountant = User::query()
            ->where('email', 'accountant@fuelflow.local')
            ->firstOrFail();
        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $accountant->id,
            'role_id' => $accountantRole->id,
            'station_id' => null,
        ]);
    }

    public function test_station_creation_assigns_manager_and_user_assignments_appear_in_roster(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $organization = Organization::query()->create([
            'name' => 'Fuel Stations Ghana',
            'slug' => 'fuel-stations-ghana',
            'currency' => 'GHS',
            'timezone' => 'Africa/Accra',
        ]);
        $homeStation = Station::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $administrator = $this->userWithRole(
            $organization,
            $homeStation,
            'administrator',
            null,
        );
        $manager = $this->userWithRole(
            $organization,
            $homeStation,
            'station_manager',
            $homeStation->id,
        );
        Sanctum::actingAs($administrator);

        $createdStation = $this->postJson('/api/v1/stations', [
            'code' => 'KSI-002',
            'station_number' => 'STN-002',
            'name' => 'Kumasi North Station',
            'registration_number' => 'GHA-002',
            'phone' => '+233 32 200 0002',
            'address' => 'North Industrial Area, Kumasi',
            'timezone' => 'Africa/Accra',
            'manager_user_id' => $manager->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.manager.id', $manager->id)
            ->assertJsonPath('data.station_number', 'STN-002')
            ->json('data');

        $attendantRole = Role::query()
            ->where('slug', 'cashier_attendant')
            ->firstOrFail();
        $this->postJson('/api/v1/users', [
            'name' => 'New Kumasi Attendant',
            'email' => 'kumasi.attendant@example.test',
            'phone' => '+233 24 000 0002',
            'password' => 'SecurePassword1!',
            'password_confirmation' => 'SecurePassword1!',
            'station_ids' => [$createdStation['id']],
            'role_assignments' => [[
                'role_id' => $attendantRole->id,
                'station_id' => $createdStation['id'],
            ]],
        ])->assertCreated();

        $this->getJson('/api/v1/stations')
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'New Kumasi Attendant',
                'email' => 'kumasi.attendant@example.test',
            ])
            ->assertJsonFragment([
                'station_number' => 'STN-002',
                'assigned_users_count' => 2,
            ]);

        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $manager->id,
            'station_id' => $createdStation['id'],
            'role_id' => Role::query()->where('slug', 'station_manager')->value('id'),
        ]);
    }

    private function userWithRole(
        Organization $organization,
        Station $station,
        string $roleSlug,
        ?string $stationId,
    ): User {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'status' => UserStatus::Active,
        ]);
        $user->stations()->attach($station->id, ['is_primary' => true]);
        $user->roleAssignments()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->firstOrFail()->id,
            'organization_id' => $organization->id,
            'station_id' => $stationId,
        ]);

        return $user;
    }
}
