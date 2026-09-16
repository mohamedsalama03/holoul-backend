<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Identity is a later batch: no speculative users table or foreign key.
            $table->uuid('actor_id')->nullable();
            $table->string('event_type', 96);
            $table->string('subject_type', 64);
            $table->uuid('subject_id');
            $table->uuid('request_id');
            $table->timestampTz('occurred_at', 6);
            $table->jsonb('metadata');
            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index('request_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE audit_events
                ADD CONSTRAINT audit_events_uuid_v7 CHECK (substring(id::text, 15, 1) = '7'),
                ADD CONSTRAINT audit_events_event_type CHECK (event_type ~ '^[a-z][a-z0-9]*([._][a-z0-9]+)*$'),
                ADD CONSTRAINT audit_events_subject_type CHECK (subject_type ~ '^[a-z][a-z0-9]*([._][a-z0-9]+)*$'),
                ADD CONSTRAINT audit_events_safe_metadata CHECK (
                    jsonb_typeof(metadata) = 'object'
                    AND octet_length(metadata::text) <= 4096
                    AND metadata - ARRAY['outcome', 'operation_id', 'attempt_number', 'duration_ms', 'affected_count', 'retryable'] = '{}'::jsonb
                    AND (NOT metadata ? 'outcome' OR metadata->'outcome' IN ('"succeeded"'::jsonb, '"failed"'::jsonb, '"denied"'::jsonb))
                    AND (NOT metadata ? 'operation_id' OR (
                        jsonb_typeof(metadata->'operation_id') = 'string'
                        AND metadata->>'operation_id' ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                    ))
                    AND (NOT metadata ? 'attempt_number' OR (metadata->'attempt_number')::text ~ '^([1-9][0-9]{0,2}|1000)$')
                    AND (NOT metadata ? 'duration_ms' OR CASE
                        WHEN (metadata->'duration_ms')::text ~ '^[0-9]{1,8}$'
                        THEN (metadata->>'duration_ms')::integer <= 86400000
                        ELSE false END)
                    AND (NOT metadata ? 'affected_count' OR CASE
                        WHEN (metadata->'affected_count')::text ~ '^[0-9]{1,7}$'
                        THEN (metadata->>'affected_count')::integer <= 1000000
                        ELSE false END)
                    AND (NOT metadata ? 'retryable' OR jsonb_typeof(metadata->'retryable') = 'boolean')
                );

            CREATE OR REPLACE FUNCTION audit_events_reject_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Audit records are append-only.' USING ERRCODE = '55000';
            END;
            $$;

            CREATE TRIGGER audit_events_no_update_or_delete
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_reject_mutation();

            CREATE TRIGGER audit_events_no_truncate
                BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION audit_events_reject_mutation();

            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'holoul_app') THEN
                    REVOKE UPDATE, DELETE, TRUNCATE ON audit_events FROM holoul_app;
                    GRANT SELECT, INSERT ON audit_events TO holoul_app;
                END IF;
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_events_reject_mutation()');
    }
};
