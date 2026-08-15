<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignUlid('started_by')
                ->nullable()
                ->after('opened_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedInteger('late_seconds')
                ->default(0)
                ->after('started_by');
            $table->foreignUlid('ended_by')
                ->nullable()
                ->after('closed_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedInteger('overtime_seconds')
                ->default(0)
                ->after('ended_by');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign(['started_by']);
            $table->dropForeign(['ended_by']);
            $table->dropColumn([
                'started_by',
                'late_seconds',
                'ended_by',
                'overtime_seconds',
            ]);
        });
    }
};
