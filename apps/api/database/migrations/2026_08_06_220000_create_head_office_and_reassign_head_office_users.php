<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $organization = DB::table('organizations')
            ->where('slug', 'fuelflow-ghana')
            ->first();

        if (! $organization) {
            return;
        }

        $now = now();
        $headOfficeId = DB::table('stations')
            ->where('organization_id', $organization->id)
            ->where('code', 'HO-001')
            ->value('id');

        if ($headOfficeId) {
            DB::table('stations')
                ->where('id', $headOfficeId)
                ->update([
                    'station_number' => 'HO-0001',
                    'name' => 'Head Office',
                    'timezone' => 'Africa/Accra',
                    'registration_number' => 'HEAD-OFFICE',
                    'phone' => '+233 30 200 0000',
                    'address' => 'Accra, Ghana',
                    'is_active' => true,
                    'updated_at' => $now,
                ]);
        } else {
            $headOfficeId = (string) Str::ulid();
            DB::table('stations')->insert([
                'id' => $headOfficeId,
                'organization_id' => $organization->id,
                'code' => 'HO-001',
                'station_number' => 'HO-0001',
                'name' => 'Head Office',
                'timezone' => 'Africa/Accra',
                'registration_number' => 'HEAD-OFFICE',
                'phone' => '+233 30 200 0000',
                'address' => 'Accra, Ghana',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('roles')
            ->where('slug', 'accountant')
            ->update([
                'scope' => 'organization',
                'description' => 'Head Office finance role for approved purchase orders, reconciliation, and reporting.',
                'updated_at' => $now,
            ]);

        $headOfficeRoles = [
            'admin@fuelflow.local' => 'administrator',
            'accountant@fuelflow.local' => 'accountant',
            'owner@fuelflow.local' => 'owner',
        ];

        foreach ($headOfficeRoles as $email => $roleSlug) {
            $user = DB::table('users')
                ->where('organization_id', $organization->id)
                ->where('email', $email)
                ->first();

            if (! $user) {
                continue;
            }

            DB::table('user_station_assignments')
                ->where('user_id', $user->id)
                ->delete();
            DB::table('user_station_assignments')->insert([
                'user_id' => $user->id,
                'station_id' => $headOfficeId,
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $roleId = DB::table('roles')
                ->where('slug', $roleSlug)
                ->value('id');

            if ($roleId) {
                DB::table('user_role_assignments')
                    ->where('user_id', $user->id)
                    ->where('role_id', $roleId)
                    ->update([
                        'station_id' => null,
                        'updated_at' => $now,
                    ]);
            }
        }
    }

    public function down(): void
    {
        $organization = DB::table('organizations')
            ->where('slug', 'fuelflow-ghana')
            ->first();

        if (! $organization) {
            return;
        }

        $stationId = DB::table('stations')
            ->where('organization_id', $organization->id)
            ->where('code', 'ACC-001')
            ->value('id');
        $headOfficeId = DB::table('stations')
            ->where('organization_id', $organization->id)
            ->where('code', 'HO-001')
            ->value('id');

        if (! $stationId) {
            return;
        }

        $now = now();
        $headOfficeRoles = [
            'admin@fuelflow.local' => 'administrator',
            'accountant@fuelflow.local' => 'accountant',
            'owner@fuelflow.local' => 'owner',
        ];

        foreach ($headOfficeRoles as $email => $roleSlug) {
            $user = DB::table('users')
                ->where('organization_id', $organization->id)
                ->where('email', $email)
                ->first();

            if (! $user) {
                continue;
            }

            DB::table('user_station_assignments')
                ->where('user_id', $user->id)
                ->delete();
            DB::table('user_station_assignments')->insert([
                'user_id' => $user->id,
                'station_id' => $stationId,
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($roleSlug === 'accountant') {
                $roleId = DB::table('roles')
                    ->where('slug', $roleSlug)
                    ->value('id');

                if ($roleId) {
                    DB::table('user_role_assignments')
                        ->where('user_id', $user->id)
                        ->where('role_id', $roleId)
                        ->update([
                            'station_id' => $stationId,
                            'updated_at' => $now,
                        ]);
                }
            }
        }

        DB::table('roles')
            ->where('slug', 'accountant')
            ->update([
                'scope' => 'station',
                'description' => 'Views financial and reconciliation data with limited report/comment edits.',
                'updated_at' => $now,
            ]);

        if ($headOfficeId) {
            DB::table('stations')
                ->where('id', $headOfficeId)
                ->update(['is_active' => false, 'updated_at' => $now]);
        }
    }
};
