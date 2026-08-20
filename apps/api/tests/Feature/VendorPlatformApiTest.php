<?php

namespace Tests\Feature;

use App\Enums\OrganizationStatus;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAuditEvent;
use App\Models\VendorUser;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VendorPlatformApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_vendor_super_user_can_log_in_and_is_isolated_from_tenant_auth(): void
    {
        $vendor = $this->vendor();

        $this->postJson('/api/v1/vendor/auth/login', [
            'email' => $vendor->email,
            'password' => 'VendorPassword1!',
        ])
            ->assertOk()
            ->assertJsonPath('data.role', 'super_user')
            ->assertJsonPath('data.email', $vendor->email);

        $this->assertAuthenticatedAs($vendor, 'vendor');
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseHas('vendor_audit_events', [
            'actor_id' => $vendor->id,
            'action' => 'vendor.auth.login',
        ]);
    }

    public function test_onboarding_atomically_creates_company_locations_and_scoped_role_accounts(): void
    {
        $vendor = $this->vendor();

        $response = $this
            ->actingAs($vendor, 'vendor')
            ->postJson('/api/v1/vendor/organizations', $this->onboardingPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Acme Fuels Limited')
            ->assertJsonPath('data.status', OrganizationStatus::Active->value)
            ->assertJsonPath('data.currency', 'GHS')
            ->assertJsonPath('data.license.duration', 18)
            ->assertJsonPath('data.license.unit', 'months')
            ->assertJsonPath('data.license.status', 'active')
            ->assertJsonCount(2, 'data.stations')
            ->assertJsonCount(2, 'data.accounts');

        $organization = Organization::query()->where('slug', 'acme-fuels')->firstOrFail();
        $headOffice = $organization->stations()->where('station_number', 'HO-0001')->firstOrFail();
        $station = $organization->stations()->where('station_number', 'STN-0100')->firstOrFail();
        $administrator = User::query()->where('email', 'admin@acme.test')->firstOrFail();
        $manager = User::query()->where('email', 'manager@acme.test')->firstOrFail();

        $this->assertSame('GHS', $organization->currency);
        $this->assertSame($vendor->id, $organization->onboarded_by_vendor_user_id);
        $this->assertSame(UserStatus::PendingFirstLogin, $administrator->status);
        $this->assertTrue($administrator->must_change_password);
        $this->assertTrue($administrator->stations()->whereKey($headOffice->id)->exists());
        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $administrator->id,
            'role_id' => Role::query()->where('slug', 'administrator')->value('id'),
            'station_id' => null,
        ]);
        $this->assertTrue($manager->stations()->whereKey($station->id)->exists());
        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $manager->id,
            'role_id' => Role::query()->where('slug', 'station_manager')->value('id'),
            'station_id' => $station->id,
        ]);
        $this->assertDatabaseHas('vendor_audit_events', [
            'action' => 'vendor.organization.onboarded',
            'subject_id' => $organization->id,
        ]);
    }

    public function test_onboarding_requires_an_administrator_and_rolls_back(): void
    {
        $payload = $this->onboardingPayload();
        $payload['accounts'] = [
            $this->account('Owner', 'owner@invalid.test', 'owner'),
        ];

        $this
            ->actingAs($this->vendor(), 'vendor')
            ->postJson('/api/v1/vendor/organizations', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accounts');

        $this->assertDatabaseMissing('organizations', ['slug' => 'acme-fuels']);
        $this->assertDatabaseMissing('users', ['email' => 'owner@invalid.test']);
    }

    public function test_vendor_can_add_each_implemented_role_and_station_scope_is_enforced(): void
    {
        $vendor = $this->vendor();
        $organizationId = $this
            ->actingAs($vendor, 'vendor')
            ->postJson('/api/v1/vendor/organizations', $this->onboardingPayload())
            ->json('data.id');
        $organization = Organization::query()->findOrFail($organizationId);

        $this
            ->actingAs($vendor, 'vendor')
            ->postJson("/api/v1/vendor/organizations/{$organization->id}/accounts", [
                ...$this->account('Company Accountant', 'accounts@acme.test', 'accountant'),
                'station_id' => null,
            ])
            ->assertCreated()
            ->assertJsonFragment(['email' => 'accounts@acme.test']);

        $accountant = User::query()->where('email', 'accounts@acme.test')->firstOrFail();
        $this->assertSame('HO-0001', $accountant->stations()->value('station_number'));
        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $accountant->id,
            'station_id' => null,
        ]);

        $this
            ->actingAs($vendor, 'vendor')
            ->postJson("/api/v1/vendor/organizations/{$organization->id}/accounts", [
                ...$this->account('Unassigned Attendant', 'attendant@acme.test', 'cashier_attendant'),
                'station_id' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('station_id');
    }

    public function test_suspending_company_revokes_and_blocks_tenant_access_until_reactivation(): void
    {
        $vendor = $this->vendor();
        $organizationId = $this
            ->actingAs($vendor, 'vendor')
            ->postJson('/api/v1/vendor/organizations', $this->onboardingPayload())
            ->json('data.id');
        $organization = Organization::query()->findOrFail($organizationId);

        $this
            ->actingAs($vendor, 'vendor')
            ->postJson("/api/v1/vendor/organizations/{$organization->id}/suspend", [
                'reason' => 'Customer contract is temporarily on hold.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrganizationStatus::Suspended->value);

        $tenantAdministrator = User::query()->where('email', 'admin@acme.test')->firstOrFail();
        Sanctum::actingAs($tenantAdministrator);
        $this
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                'Access to this company has been suspended by the vendor.',
            );

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@acme.test',
            'password' => 'TemporaryPassword1!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this
            ->actingAs($vendor, 'vendor')
            ->postJson("/api/v1/vendor/organizations/{$organization->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', OrganizationStatus::Active->value);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@acme.test',
            'password' => 'TemporaryPassword1!',
        ])->assertOk();
    }

    public function test_vendor_can_edit_deactivate_and_renew_company_license(): void
    {
        $vendor = $this->vendor();
        $organizationId = $this
            ->actingAs($vendor, 'vendor')
            ->postJson('/api/v1/vendor/organizations', $this->onboardingPayload())
            ->json('data.id');
        $organization = Organization::query()->findOrFail($organizationId);

        $this
            ->actingAs($vendor, 'vendor')
            ->patchJson("/api/v1/vendor/organizations/{$organization->id}/license", [
                'duration' => 2,
                'unit' => 'years',
            ])
            ->assertOk()
            ->assertJsonPath('data.license.duration', 2)
            ->assertJsonPath('data.license.unit', 'years')
            ->assertJsonPath('data.license.status', 'active');

        $this
            ->actingAs($vendor, 'vendor')
            ->postJson("/api/v1/vendor/organizations/{$organization->id}/license/deactivate", [
                'reason' => 'The customer requested cancellation of the platform license.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'license_deactivated')
            ->assertJsonPath('data.license.status', 'license_deactivated');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@acme.test',
            'password' => 'TemporaryPassword1!',
        ])->assertUnprocessable();

        $this
            ->actingAs($vendor, 'vendor')
            ->patchJson("/api/v1/vendor/organizations/{$organization->id}/license", [
                'duration' => 6,
                'unit' => 'months',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.license.duration', 6)
            ->assertJsonPath('data.license.deactivation_reason', null);

        $this->assertDatabaseHas('vendor_audit_events', [
            'action' => 'vendor.organization.license_deactivated',
            'subject_id' => $organization->id,
        ]);
        $this->assertSame(
            3,
            VendorAuditEvent::query()
                ->where('subject_id', $organization->id)
                ->whereIn('action', [
                    'vendor.organization.license_updated',
                    'vendor.organization.license_deactivated',
                ])
                ->count(),
        );
    }

    public function test_expired_license_blocks_tenant_login_and_appears_in_vendor_filter(): void
    {
        $vendor = $this->vendor();
        $organizationId = $this
            ->actingAs($vendor, 'vendor')
            ->postJson('/api/v1/vendor/organizations', $this->onboardingPayload())
            ->json('data.id');
        $organization = Organization::query()->findOrFail($organizationId);
        $organization->forceFill(['license_expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@acme.test',
            'password' => 'TemporaryPassword1!',
        ])->assertUnprocessable();

        $this
            ->actingAs($vendor, 'vendor')
            ->getJson('/api/v1/vendor/organizations?status=expired')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'expired');
    }

    public function test_tenant_user_cannot_access_vendor_routes(): void
    {
        $organization = Organization::factory()->create();
        $tenantUser = User::factory()->for($organization)->create();

        $this
            ->actingAs($tenantUser)
            ->getJson('/api/v1/vendor/dashboard')
            ->assertUnauthorized();
    }

    public function test_vendor_audit_model_rejects_application_updates_and_deletes(): void
    {
        $event = VendorAuditEvent::query()->create([
            'action' => 'vendor.test',
            'created_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $event->update(['action' => 'vendor.changed']);
    }

    /** @return array<string, mixed> */
    private function onboardingPayload(): array
    {
        return [
            'company' => [
                'name' => 'Acme Fuels Limited',
                'slug' => 'acme-fuels',
                'registration_number' => 'CS-100200',
                'contact_email' => 'contact@acme.test',
                'phone' => '+233 20 100 2000',
                'address' => '1 Independence Avenue, Accra',
                'timezone' => 'Africa/Accra',
            ],
            'station' => [
                'name' => 'Acme Accra Central',
                'code' => 'ACC-100',
                'station_number' => 'STN-0100',
                'registration_number' => 'EPA-100',
                'phone' => '+233 30 100 2000',
                'address' => '2 Ring Road, Accra',
            ],
            'license' => [
                'duration' => 18,
                'unit' => 'months',
            ],
            'accounts' => [
                $this->account('Acme Administrator', 'admin@acme.test', 'administrator'),
                $this->account('Acme Station Manager', 'manager@acme.test', 'station_manager'),
            ],
        ];
    }

    /** @return array<string, string> */
    private function account(string $name, string $email, string $role): array
    {
        return [
            'name' => $name,
            'email' => $email,
            'phone' => '+233 20 555 0101',
            'role_slug' => $role,
            'password' => 'TemporaryPassword1!',
            'password_confirmation' => 'TemporaryPassword1!',
        ];
    }

    private function vendor(): VendorUser
    {
        return VendorUser::query()->create([
            'name' => 'Vendor Administrator',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+233 20 000 0099',
            'role' => 'super_user',
            'status' => UserStatus::Active,
            'must_change_password' => false,
            'email_verified_at' => now(),
            'password' => Hash::make('VendorPassword1!'),
        ]);
    }
}
