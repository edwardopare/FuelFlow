<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_audit_event_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS audit_events_append_only ON audit_events;
            CREATE TRIGGER audit_events_append_only
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW
                EXECUTE FUNCTION prevent_audit_event_mutation();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS audit_events_append_only ON audit_events;
            DROP FUNCTION IF EXISTS prevent_audit_event_mutation();
            SQL);
    }
};
