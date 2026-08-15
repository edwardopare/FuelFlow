<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', 'procurement.purchase_orders.approve')
            ->value('id');
        $administratorId = DB::table('roles')
            ->where('slug', 'administrator')
            ->value('id');
        $ownerId = DB::table('roles')
            ->where('slug', 'owner')
            ->value('id');

        if ($permissionId && $ownerId) {
            DB::table('permission_role')
                ->where('permission_id', $permissionId)
                ->where('role_id', $ownerId)
                ->delete();
        }

        if ($permissionId && $administratorId) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $administratorId,
            ]);
        }

        DB::table('roles')
            ->where('slug', 'owner')
            ->update([
                'description' => 'Portfolio visibility and reporting access.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', 'procurement.purchase_orders.approve')
            ->value('id');
        $ownerId = DB::table('roles')
            ->where('slug', 'owner')
            ->value('id');

        if ($permissionId && $ownerId) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $ownerId,
            ]);
        }

        DB::table('roles')
            ->where('slug', 'owner')
            ->update([
                'description' => 'Portfolio visibility and purchase order approval.',
                'updated_at' => now(),
            ]);
    }
};
