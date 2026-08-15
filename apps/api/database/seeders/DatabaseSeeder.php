<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        if (app()->environment('local') && env('SEED_DEMO_DATA', false)) {
            $this->call(VendorDemoDataSeeder::class);
            $this->call(DemoDataSeeder::class);
        }
    }
}
