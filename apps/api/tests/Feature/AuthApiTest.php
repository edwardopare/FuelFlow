<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Station;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_log_in_and_login_is_audited(): void
    {
        [$user] = $this->makeUser('administrator', UserStatus::Active);
        $user->forceFill(['password' => Hash::make('ValidPassword1!')])->save();

        $response = $this
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'ValidPassword1!',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.organization.currency', 'GHS')
            ->assertHeader('X-Request-ID');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'auth.login',
            'actor_id' => $user->id,
        ]);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        [$user] = $this->makeUser('administrator', UserStatus::Inactive);
        $user->forceFill(['password' => Hash::make('ValidPassword1!')])->save();

        $this
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'ValidPassword1!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'auth.login',
            'actor_id' => $user->id,
        ]);
    }

    public function test_authenticated_user_can_log_out_and_logout_is_audited(): void
    {
        [$user] = $this->makeUser('administrator', UserStatus::Active);
        $user->forceFill(['password' => Hash::make('ValidPassword1!')])->save();

        $this
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'ValidPassword1!',
            ])
            ->assertOk();

        $this
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame(
            1,
            AuditEvent::query()
                ->where('action', 'auth.logout')
                ->where('actor_id', $user->id)
                ->count(),
        );
    }

    public function test_active_user_can_change_password_from_their_profile(): void
    {
        [$user] = $this->makeUser('owner', UserStatus::Active);
        $user->forceFill([
            'password' => Hash::make('CurrentPassword1!'),
            'must_change_password' => false,
        ])->save();

        $this
            ->actingAs($user)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'CurrentPassword1!',
                'password' => 'ReplacementPassword2!',
                'password_confirmation' => 'ReplacementPassword2!',
            ])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false)
            ->assertJsonPath('data.status', UserStatus::Active->value);

        $this->assertTrue(
            Hash::check('ReplacementPassword2!', $user->fresh()->password),
        );
        $this->assertDatabaseHas('audit_events', [
            'action' => 'auth.password_changed',
            'actor_id' => $user->id,
        ]);
    }

    public function test_pending_first_login_user_can_set_permanent_password_without_reentering_temporary_password(): void
    {
        [$user] = $this->makeUser('station_manager', UserStatus::PendingFirstLogin);
        $user->forceFill([
            'password' => 'TemporaryPassword1!',
            'must_change_password' => true,
        ])->save();

        $this
            ->actingAs($user)
            ->postJson('/api/v1/auth/change-password', [
                'password' => 'PermanentPassword2!',
                'password_confirmation' => 'PermanentPassword2!',
            ])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false)
            ->assertJsonPath('data.status', UserStatus::Active->value);

        $user->refresh();
        $this->assertTrue(Hash::check('PermanentPassword2!', $user->password));
        $this->assertDatabaseHas('audit_events', [
            'action' => 'auth.password_changed',
            'actor_id' => $user->id,
        ]);
    }

    public function test_active_user_must_supply_their_current_password_to_change_it(): void
    {
        [$user] = $this->makeUser('owner', UserStatus::Active);
        $user->forceFill([
            'password' => 'CurrentPassword1!',
            'must_change_password' => false,
        ])->save();

        $this
            ->actingAs($user)
            ->postJson('/api/v1/auth/change-password', [
                'password' => 'ReplacementPassword2!',
                'password_confirmation' => 'ReplacementPassword2!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('CurrentPassword1!', $user->fresh()->password));
    }

    public function test_user_can_request_and_complete_password_reset(): void
    {
        Notification::fake();
        [$user] = $this->makeUser('owner', UserStatus::Active);
        $user->forceFill([
            'password' => Hash::make('CurrentPassword1!'),
            'must_change_password' => false,
        ])->save();
        $token = null;
        $resetUrl = null;

        $this
            ->postJson('/api/v1/auth/forgot-password', [
                'email' => $user->email,
            ])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'If an account exists for that email address, a password reset link has been sent.',
            );

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (
                &$resetUrl,
                &$token,
                $user,
            ): bool {
                $token = $notification->token;
                $resetUrl = $notification->toMail($user)->actionUrl;

                return true;
            },
        );
        $this->assertStringStartsWith(
            'http://localhost:5173/reset-password/',
            $resetUrl,
        );

        $this
            ->postJson('/api/v1/auth/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'RecoveredPassword2!',
                'password_confirmation' => 'RecoveredPassword2!',
            ])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Your password has been reset successfully.',
            );

        $this->assertTrue(
            Hash::check('RecoveredPassword2!', $user->fresh()->password),
        );
        $this->assertDatabaseHas('audit_events', [
            'action' => 'auth.password_reset',
            'subject_id' => $user->id,
        ]);
    }

    public function test_organization_idle_timeout_expires_authenticated_session(): void
    {
        [$user, $organization] = $this->makeUser('owner', UserStatus::Active);
        SystemSetting::query()->create([
            'organization_id' => $organization->id,
            'key' => 'session_timeout_minutes',
            'value' => 5,
            'updated_by' => $user->id,
        ]);

        $this
            ->actingAs($user)
            ->withSession([
                'auth_last_activity' => now()->subMinutes(6)->timestamp,
            ])
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                'Your session expired due to inactivity. Please sign in again.',
            );

        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', [
            'action' => 'auth.session_expired',
            'actor_id' => $user->id,
        ]);
    }

    /**
     * @return array{User, Organization, Station}
     */
    private function makeUser(string $roleSlug, UserStatus $status): array
    {
        $this->seed(RolePermissionSeeder::class);
        $organization = Organization::factory()->create();
        $station = Station::factory()->for($organization)->create();
        $user = User::factory()->for($organization)->create(['status' => $status]);
        $user->stations()->attach($station, ['is_primary' => true]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roleAssignments()->create([
            'role_id' => $role->id,
            'organization_id' => $organization->id,
            'station_id' => $role->scope === 'station' ? $station->id : null,
        ]);

        return [$user, $organization, $station];
    }
}
