<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('role', 32)->default('super_user');
            $table->string('status', 32)->default('pending_first_login');
            $table->boolean('must_change_password')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_authenticated_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('status', 32)->default('active')->after('slug');
            $table->string('registration_number')->nullable()->after('status');
            $table->string('contact_email')->nullable()->after('registration_number');
            $table->string('phone', 40)->nullable()->after('contact_email');
            $table->text('address')->nullable()->after('phone');
            $table->foreignUlid('onboarded_by_vendor_user_id')
                ->nullable()
                ->after('timezone')
                ->constrained('vendor_users')
                ->restrictOnDelete();
            $table->timestampTz('activated_at')->nullable()->after('onboarded_by_vendor_user_id');
            $table->timestampTz('suspended_at')->nullable()->after('activated_at');
            $table->index(['status', 'created_at']);
        });

        DB::table('organizations')->whereNull('activated_at')->update([
            'activated_at' => now(),
        ]);

        Schema::create('vendor_audit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('actor_id')
                ->nullable()
                ->constrained('vendor_users')
                ->restrictOnDelete();
            $table->string('action', 120);
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('request_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['actor_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_vendor_audit_event_mutation()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'vendor_audit_events is append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER vendor_audit_events_append_only
                    BEFORE UPDATE OR DELETE ON vendor_audit_events
                    FOR EACH ROW
                    EXECUTE FUNCTION prevent_vendor_audit_event_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS vendor_audit_events_append_only ON vendor_audit_events;
                DROP FUNCTION IF EXISTS prevent_vendor_audit_event_mutation();
                SQL);
        }

        Schema::dropIfExists('vendor_audit_events');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropForeign(['onboarded_by_vendor_user_id']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn([
                'status',
                'registration_number',
                'contact_email',
                'phone',
                'address',
                'onboarded_by_vendor_user_id',
                'activated_at',
                'suspended_at',
            ]);
        });

        Schema::dropIfExists('vendor_users');
    }
};
