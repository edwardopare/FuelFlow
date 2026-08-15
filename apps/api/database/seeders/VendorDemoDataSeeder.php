<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\VendorUser;
use Illuminate\Database\Seeder;

class VendorDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        VendorUser::query()->updateOrCreate(
            ['email' => 'vendor@fuelflow.local'],
            [
                'name' => 'FuelFlow Vendor Super User',
                'phone' => '+233 20 000 0099',
                'role' => 'super_user',
                'status' => UserStatus::Active,
                'must_change_password' => false,
                'email_verified_at' => now(),
                'password' => env('DEMO_VENDOR_PASSWORD', 'ChangeMeNow1!'),
            ],
        );
    }
}
