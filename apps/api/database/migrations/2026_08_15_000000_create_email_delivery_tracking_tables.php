<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_notification_dispatches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('license_expires_at');
            $table->unsignedTinyInteger('days_before_expiry');
            $table->json('recipients');
            $table->timestampTz('dispatched_at');
            $table->timestamps();
            $table->unique(
                ['organization_id', 'license_expires_at', 'days_before_expiry'],
                'license_notification_dispatch_unique',
            );
        });

        Schema::create('report_schedule_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('report_schedule_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('scheduled_for');
            $table->string('recipient');
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(
                ['report_schedule_id', 'scheduled_for', 'recipient'],
                'report_schedule_delivery_unique',
            );
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedule_deliveries');
        Schema::dropIfExists('license_notification_dispatches');
    }
};
