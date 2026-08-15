<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->ulid('recurrence_id')->nullable()->after('code');
            $table->index(['recurrence_id', 'scheduled_start']);
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(['recurrence_id', 'scheduled_start']);
            $table->dropColumn('recurrence_id');
        });
    }
};
