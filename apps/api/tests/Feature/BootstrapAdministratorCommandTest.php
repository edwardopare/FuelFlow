<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_secure_initial_administrator_and_head_office(): void
    {
        $options = [
            '--organization' => 'Production Fuel Company',
            '--organization-slug' => 'production-fuel-company',
            '--admin-name' => 'Initial Administrator',
            '--admin-email' => 'initial.admin@example.test',
            '--admin-password' => 'TemporaryPassword9!',
        ];

        $this->artisan('fuelflow:bootstrap-admin', $options)
            ->expectsOutput('Initial Administrator created successfully.')
            ->assertSuccessful();

        $organization = Organization::query()
            ->where('slug', 'production-fuel-company')
            ->firstOrFail();
        $station = Station::query()
            ->where('organization_id', $organization->id)
            ->where('code', 'HEAD-OFFICE')
            ->firstOrFail();
        $administrator = User::query()
            ->where('email', 'initial.admin@example.test')
            ->firstOrFail();

        $this->assertSame('GHS', $organization->currency);
        $this->assertSame('HO-0001', $station->station_number);
        $this->assertSame(UserStatus::PendingFirstLogin, $administrator->status);
        $this->assertTrue($administrator->must_change_password);
        $this->assertTrue(Hash::check('TemporaryPassword9!', $administrator->password));
        $this->assertTrue(
            $administrator->stations()->whereKey($station->id)->exists(),
        );
        $this->assertTrue(
            $administrator->roleAssignments()
                ->whereNull('station_id')
                ->whereHas('role', fn ($role) => $role->where('slug', 'administrator'))
                ->exists(),
        );
        $this->assertDatabaseHas('audit_events', [
            'action' => 'system.initial_administrator_created',
            'actor_id' => $administrator->id,
        ]);

        $this->artisan('fuelflow:bootstrap-admin', $options)
            ->expectsOutput('A user with this email address already exists. No changes were made.')
            ->assertFailed();
        $this->assertSame(1, User::query()->where('email', $administrator->email)->count());
    }
}
