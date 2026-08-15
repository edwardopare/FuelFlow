<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\VendorUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapVendorSuperUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_first_login_vendor_super_user(): void
    {
        $this->artisan('fuelflow:bootstrap-vendor', [
            '--name' => 'Primary Vendor User',
            '--email' => 'vendor@example.test',
            '--password' => 'SecureVendorPassword1!',
            '--phone' => '+233 20 123 4567',
        ])->assertSuccessful();

        $user = VendorUser::query()->where('email', 'vendor@example.test')->firstOrFail();
        $this->assertSame('super_user', $user->role);
        $this->assertSame(UserStatus::PendingFirstLogin, $user->status);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('SecureVendorPassword1!', $user->password));
    }

    public function test_command_refuses_duplicate_vendor_email(): void
    {
        VendorUser::query()->create([
            'name' => 'Existing Vendor',
            'email' => 'vendor@example.test',
            'role' => 'super_user',
            'status' => UserStatus::Active,
            'must_change_password' => false,
            'password' => 'SecureVendorPassword1!',
        ]);

        $this->artisan('fuelflow:bootstrap-vendor', [
            '--name' => 'Replacement Vendor',
            '--email' => 'vendor@example.test',
            '--password' => 'AnotherSecurePassword2!',
        ])->assertFailed();

        $this->assertSame(1, VendorUser::query()->count());
    }
}
