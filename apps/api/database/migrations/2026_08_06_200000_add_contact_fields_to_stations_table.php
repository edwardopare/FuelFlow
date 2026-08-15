<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->string('station_number', 40)->nullable()->after('code');
            $table->string('phone', 40)->nullable()->after('registration_number');
            $table->text('address')->nullable()->after('phone');
            $table->unique(['organization_id', 'station_number']);
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'station_number']);
            $table->dropColumn(['station_number', 'phone', 'address']);
        });
    }
};
