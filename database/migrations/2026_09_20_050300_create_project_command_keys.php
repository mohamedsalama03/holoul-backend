<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE project_command_keys (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                operation varchar(64) NOT NULL CHECK(operation IN ('project.convert','project.evidence','project.advance','project.hold',
                    'project.resume','project.fail','project.cancel','project.confirm','project.team.add','project.team.remove',
                    'project.milestone.create','project.milestone.update','project.milestone.start','project.milestone.delay',
                    'project.milestone.complete','project.update.publish')),
                key_hash char(64) NOT NULL CHECK(key_hash ~ '^[a-f0-9]{64}$'),
                input_hash char(64) NOT NULL CHECK(input_hash ~ '^[a-f0-9]{64}$'),
                target_id uuid NOT NULL,
                project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
                result_id uuid NOT NULL CHECK(substring(result_id::text,15,1)='7'),
                result_state varchar(24) NOT NULL CHECK(result_state IN ('planning','design','development','testing','deployment','on_hold','completed','cancelled')),
                result_version bigint NOT NULL CHECK(result_version>0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                expires_at timestamptz NOT NULL DEFAULT (clock_timestamp()+interval '72 hours'),
                CHECK(expires_at>created_at),
                UNIQUE(actor_id,operation,key_hash)
            );
            CREATE OR REPLACE FUNCTION project_guard_command_target() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (NEW.operation='project.convert' AND NOT EXISTS(SELECT 1 FROM projects WHERE id=NEW.project_id AND source_request_id=NEW.target_id))
                    OR (NEW.operation<>'project.convert' AND NEW.target_id<>NEW.project_id) THEN
                    RAISE EXCEPTION 'Command receipt must match its source or project.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER project_command_target BEFORE INSERT ON project_command_keys FOR EACH ROW EXECUTE FUNCTION project_guard_command_target();
            CREATE TRIGGER project_command_history BEFORE UPDATE OR DELETE ON project_command_keys FOR EACH ROW EXECUTE FUNCTION intake_history_append_only();
            CREATE TRIGGER project_command_no_truncate BEFORE TRUNCATE ON project_command_keys FOR EACH STATEMENT EXECUTE FUNCTION intake_history_append_only();
            DO $$ BEGIN
                IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN REVOKE UPDATE,DELETE,TRUNCATE ON project_command_keys FROM holoul_app; END IF;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE project_command_keys; DROP FUNCTION project_guard_command_target();');
    }
};
