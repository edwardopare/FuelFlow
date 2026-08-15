<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('license_term_value')->default(1)->after('timezone');
            $table->string('license_term_unit', 16)->default('years')->after('license_term_value');
            $table->timestampTz('license_started_at')->nullable()->after('license_term_unit');
            $table->timestampTz('license_expires_at')->nullable()->after('license_started_at');
            $table->timestampTz('license_deactivated_at')->nullable()->after('license_expires_at');
            $table->text('license_deactivation_reason')->nullable()->after('license_deactivated_at');
            $table->index('license_expires_at');
        });

        DB::table('organizations')->update([
            'license_term_value' => 1,
            'license_term_unit' => 'years',
            'license_started_at' => now(),
            'license_expires_at' => now()->addYear(),
        ]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex(['license_expires_at']);
            $table->dropColumn([
                'license_term_value',
                'license_term_unit',
                'license_started_at',
                'license_expires_at',
                'license_deactivated_at',
                'license_deactivation_reason',
            ]);
        });
    }
};
