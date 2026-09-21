<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE ai_budget_days (
                day date PRIMARY KEY,
                reserved_microusd bigint NOT NULL DEFAULT 0 CHECK(reserved_microusd>=0),
                spent_microusd bigint NOT NULL DEFAULT 0 CHECK(spent_microusd>=0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp()
            );
            CREATE TABLE ai_runs (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
                parent_type varchar(12) NOT NULL CHECK(parent_type IN ('request','project')),
                parent_id uuid NOT NULL,
                request_id uuid GENERATED ALWAYS AS (CASE WHEN parent_type='request' THEN parent_id END) STORED,
                project_id uuid GENERATED ALWAYS AS (CASE WHEN parent_type='project' THEN parent_id END) STORED,
                source_type varchar(24) NOT NULL CHECK(source_type IN ('intake_draft','intake_revision','project_document')),
                source_id uuid NOT NULL CHECK(substring(source_id::text,15,1)='7'),
                source_version bigint NOT NULL CHECK(source_version>0),
                source_hash char(64) NOT NULL CHECK(source_hash ~ '^[a-f0-9]{64}$'),
                source_text text NOT NULL CHECK(char_length(source_text)<=20000),
                document_id uuid,
                document_checksum char(64) CHECK(document_checksum ~ '^[a-f0-9]{64}$'),
                document_object_version varchar(1024),
                taxonomy jsonb NOT NULL DEFAULT '[]' CHECK(jsonb_typeof(taxonomy)='array' AND jsonb_array_length(taxonomy)<=100),
                purpose varchar(32) NOT NULL CHECK(purpose IN ('improve_description','suggest_category','analyze_document','extract_requirements','missing_information')),
                provider varchar(32) NOT NULL CHECK(provider ~ '^[a-z][a-z0-9_]{0,31}$'),
                model varchar(80) NOT NULL,
                prompt_version varchar(64) NOT NULL,
                schema_version varchar(64) NOT NULL,
                consent_version varchar(32) NOT NULL DEFAULT 'ai-consent-v1' CHECK(consent_version='ai-consent-v1'),
                consented_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                state varchar(16) NOT NULL CHECK(state IN ('pending','processing','succeeded','failed','unavailable','cancelled')),
                lock_version bigint NOT NULL DEFAULT 1 CHECK(lock_version>0),
                budget_day date NOT NULL REFERENCES ai_budget_days(day) ON DELETE RESTRICT,
                reserved_cost_microusd bigint NOT NULL CHECK(reserved_cost_microusd>=0),
                reservation_state varchar(16) NOT NULL CHECK(reservation_state IN ('reserved','settled','released','uncertain')),
                actual_cost_microusd bigint CHECK(actual_cost_microusd>=0 AND actual_cost_microusd<=reserved_cost_microusd),
                input_tokens integer CHECK(input_tokens>=0), output_tokens integer CHECK(output_tokens>=0),
                operation_id uuid UNIQUE REFERENCES async_operations(id) ON DELETE RESTRICT,
                dispatch_fence bigint CHECK(dispatch_fence>0),
                provider_operation_id varchar(128) CHECK(provider_operation_id ~ '^[A-Za-z0-9_.:-]{1,128}$'),
                failure_code varchar(64) CHECK(failure_code ~ '^[a-z][a-z0-9_]{0,63}$'),
                idempotency_hash char(64) NOT NULL CHECK(idempotency_hash ~ '^[a-f0-9]{64}$'),
                input_hash char(64) NOT NULL CHECK(input_hash ~ '^[a-f0-9]{64}$'),
                requested_correlation_id uuid NOT NULL,
                started_at timestamptz, completed_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(actor_id,idempotency_hash), UNIQUE(id,customer_id,parent_id),
                CHECK(source_type<>'project_document' OR document_id IS NOT NULL),
                CHECK((document_id IS NULL)=(document_checksum IS NULL)), CHECK((document_id IS NULL)=(document_object_version IS NULL)),
                CHECK(source_type<>'project_document' OR source_id=document_id),
                CHECK(document_id IS NULL OR (source_text='' AND char_length(document_object_version)>0)),
                CHECK(parent_type='request' OR source_type='project_document'),
                CHECK(purpose<>'analyze_document' OR document_id IS NOT NULL),
                CHECK(purpose NOT IN ('improve_description','suggest_category') OR (source_type='intake_draft' AND parent_type='request')),
                CHECK((state IN ('pending','processing'))=(completed_at IS NULL)),
                CHECK((reservation_state='settled')=(actual_cost_microusd IS NOT NULL)),
                FOREIGN KEY(request_id,customer_id) REFERENCES project_requests(id,customer_id) ON DELETE RESTRICT,
                FOREIGN KEY(project_id,customer_id) REFERENCES projects(id,customer_id) ON DELETE RESTRICT,
                FOREIGN KEY(document_id,customer_id,parent_id) REFERENCES documents(id,customer_id,parent_id) ON DELETE RESTRICT
            );
            CREATE INDEX ai_runs_actor_list ON ai_runs(actor_id,created_at,id);
            CREATE INDEX ai_runs_parent_list ON ai_runs(parent_type,parent_id,id);
            CREATE INDEX ai_runs_limits ON ai_runs(budget_day,actor_id,state);
            CREATE INDEX ai_runs_document_retained ON ai_runs(document_id) WHERE document_id IS NOT NULL;
            CREATE TABLE ai_suggestions (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                run_id uuid NOT NULL UNIQUE REFERENCES ai_runs(id) ON DELETE RESTRICT,
                output jsonb NOT NULL CHECK(jsonb_typeof(output)='object' AND octet_length(output::text)<=32000),
                state varchar(16) NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','applied','dismissed')),
                decided_by uuid REFERENCES users(id) ON DELETE RESTRICT,
                decided_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CHECK((state='pending')=(decided_by IS NULL)), CHECK((state='pending')=(decided_at IS NULL))
            );
            CREATE TABLE ai_provider_attempts (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                run_id uuid NOT NULL REFERENCES ai_runs(id) ON DELETE RESTRICT,
                fence bigint NOT NULL CHECK(fence>0),
                outcome varchar(16) NOT NULL CHECK(outcome IN ('started','retryable','succeeded','failed','uncertain','cancelled')),
                failure_code varchar(64) CHECK(failure_code ~ '^[a-z][a-z0-9_]{0,63}$'),
                retry_after_seconds integer CHECK(retry_after_seconds BETWEEN 0 AND 3600),
                provider_operation_id varchar(128) CHECK(provider_operation_id ~ '^[A-Za-z0-9_.:-]{1,128}$'),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(), completed_at timestamptz,
                UNIQUE(run_id,fence), CHECK((outcome='started')=(completed_at IS NULL))
            );
            CREATE TABLE ai_command_keys (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                run_id uuid NOT NULL REFERENCES ai_runs(id) ON DELETE RESTRICT,
                operation varchar(32) NOT NULL CHECK(operation IN ('apply','dismiss','cancel')),
                key_hash char(64) NOT NULL CHECK(key_hash ~ '^[a-f0-9]{64}$'),
                input_hash char(64) NOT NULL CHECK(input_hash ~ '^[a-f0-9]{64}$'),
                result_state varchar(16) NOT NULL CHECK(result_state IN ('pending','processing','succeeded','failed','unavailable','cancelled')),
                result_version bigint NOT NULL CHECK(result_version>0),
                suggestion_state varchar(16) CHECK(suggestion_state IN ('pending','applied','dismissed')),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                expires_at timestamptz NOT NULL DEFAULT(clock_timestamp()+interval '72 hours'),
                UNIQUE(actor_id,operation,key_hash)
            );
            CREATE TRIGGER ai_command_history BEFORE UPDATE OR DELETE ON ai_command_keys FOR EACH ROW EXECUTE FUNCTION intake_history_append_only();
            CREATE OR REPLACE FUNCTION ai_guard_source_insert() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.state NOT IN ('pending','unavailable') OR NEW.lock_version<>1 OR NEW.dispatch_fence IS NOT NULL THEN
                    RAISE EXCEPTION 'AI run must begin with an undispatched admission outcome.' USING ERRCODE='23514'; END IF;
                IF NEW.source_type='intake_draft' AND NOT EXISTS(SELECT 1 FROM request_drafts WHERE id=NEW.source_id AND request_id=NEW.parent_id AND customer_id=NEW.customer_id AND is_open) THEN
                    RAISE EXCEPTION 'AI draft source must match its exact parent.' USING ERRCODE='23514'; END IF;
                IF NEW.source_type='intake_revision' AND NOT EXISTS(SELECT 1 FROM request_revisions WHERE id=NEW.source_id AND request_id=NEW.parent_id AND customer_id=NEW.customer_id) THEN
                    RAISE EXCEPTION 'AI revision source must match its exact parent.' USING ERRCODE='23514'; END IF;
                IF NEW.document_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM documents WHERE id=NEW.document_id AND state='available' AND expected_sha256=NEW.document_checksum AND storage_version=NEW.document_object_version) THEN
                    RAISE EXCEPTION 'AI document source requires exact cleared bytes.' USING ERRCODE='23514'; END IF;
                IF NEW.document_id IS NOT NULL AND
                    ((NEW.source_type='intake_draft' AND NOT EXISTS(SELECT 1 FROM intake_draft_documents WHERE draft_id=NEW.source_id AND document_id=NEW.document_id))
                    OR (NEW.source_type='intake_revision' AND NOT EXISTS(SELECT 1 FROM intake_revision_documents WHERE revision_id=NEW.source_id AND document_id=NEW.document_id))
                    OR (NEW.source_type='project_document' AND NOT EXISTS(SELECT 1 FROM project_documents WHERE project_id=NEW.parent_id AND document_id=NEW.document_id))) THEN
                    RAISE EXCEPTION 'AI document source requires the exact authorized attachment.' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER ai_source_insert_guard BEFORE INSERT ON ai_runs FOR EACH ROW EXECUTE FUNCTION ai_guard_source_insert();
            CREATE OR REPLACE FUNCTION ai_preserve_run() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'AI source/run history is retained.' USING ERRCODE='23514'; END IF;
                IF (to_jsonb(NEW)-ARRAY['request_id','project_id','state','lock_version','reservation_state','actual_cost_microusd','input_tokens','output_tokens','operation_id','dispatch_fence','provider_operation_id','failure_code','started_at','completed_at','updated_at'])
                    IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['request_id','project_id','state','lock_version','reservation_state','actual_cost_microusd','input_tokens','output_tokens','operation_id','dispatch_fence','provider_operation_id','failure_code','started_at','completed_at','updated_at']) THEN
                    RAISE EXCEPTION 'AI source identity and snapshots are immutable.' USING ERRCODE='23514';
                END IF;
                IF NEW.lock_version<>OLD.lock_version+1 OR (OLD.operation_id IS NOT NULL AND NEW.operation_id IS DISTINCT FROM OLD.operation_id)
                    OR (OLD.state IN ('succeeded','failed','unavailable','cancelled') AND NEW.state<>OLD.state)
                    OR (OLD.reservation_state IN ('released','settled') AND NEW.reservation_state<>OLD.reservation_state) THEN
                    RAISE EXCEPTION 'Invalid AI state/version mutation.' USING ERRCODE='23514';
                END IF;
                IF (OLD.state='pending' AND NEW.state NOT IN ('pending','processing','failed','unavailable','cancelled'))
                    OR (OLD.reservation_state='uncertain' AND NEW.reservation_state NOT IN ('uncertain','settled'))
                    OR (OLD.reservation_state IN ('released','settled') AND NEW.actual_cost_microusd IS DISTINCT FROM OLD.actual_cost_microusd)
                    OR (OLD.state IN ('succeeded','failed','unavailable','cancelled') AND
                        (NEW.input_tokens,NEW.output_tokens,NEW.provider_operation_id,NEW.failure_code,NEW.started_at,NEW.completed_at,NEW.dispatch_fence)
                        IS DISTINCT FROM (OLD.input_tokens,OLD.output_tokens,OLD.provider_operation_id,OLD.failure_code,OLD.started_at,OLD.completed_at,OLD.dispatch_fence)) THEN
                    RAISE EXCEPTION 'AI terminal outcomes and settled usage are retained.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER ai_runs_guard BEFORE UPDATE OR DELETE ON ai_runs FOR EACH ROW EXECUTE FUNCTION ai_preserve_run();
            CREATE OR REPLACE FUNCTION ai_preserve_suggestion() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' OR OLD.state<>'pending' OR NEW.state NOT IN ('applied','dismissed')
                    OR (NEW.id,NEW.run_id,NEW.output,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.run_id,OLD.output,OLD.created_at) THEN
                    RAISE EXCEPTION 'AI suggestions and human decisions are immutable.' USING ERRCODE='23514'; END IF;
                IF NOT EXISTS(SELECT 1 FROM ai_runs WHERE id=NEW.run_id AND actor_id=NEW.decided_by AND state='succeeded') THEN
                    RAISE EXCEPTION 'AI suggestion decisions require the run requester.' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER ai_suggestions_guard BEFORE UPDATE OR DELETE ON ai_suggestions FOR EACH ROW EXECUTE FUNCTION ai_preserve_suggestion();
            CREATE OR REPLACE FUNCTION ai_guard_result() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_TABLE_NAME='ai_runs' THEN
                    IF NEW.state='succeeded' AND NOT EXISTS(SELECT 1 FROM ai_suggestions WHERE run_id=NEW.id) THEN
                        RAISE EXCEPTION 'Successful AI run requires suggestion.' USING ERRCODE='23514'; END IF;
                ELSE
                    IF NOT EXISTS(SELECT 1 FROM ai_runs WHERE id=NEW.run_id AND state='succeeded') THEN
                        RAISE EXCEPTION 'Suggestion requires successful run.' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER ai_run_result_required AFTER INSERT OR UPDATE ON ai_runs DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ai_guard_result();
            CREATE CONSTRAINT TRIGGER ai_suggestion_result_required AFTER INSERT ON ai_suggestions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ai_guard_result();
            CREATE OR REPLACE FUNCTION ai_guard_attempt() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' OR OLD.outcome<>'started' OR NEW.outcome='started'
                    OR (NEW.id,NEW.run_id,NEW.fence,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.run_id,OLD.fence,OLD.created_at) THEN
                    RAISE EXCEPTION 'AI provider attempt history is immutable.' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER ai_attempts_guard BEFORE UPDATE OR DELETE ON ai_provider_attempts FOR EACH ROW EXECUTE FUNCTION ai_guard_attempt();
            DO $$ DECLARE target text; BEGIN
                FOREACH target IN ARRAY ARRAY['ai_budget_days','ai_runs','ai_suggestions','ai_provider_attempts','ai_command_keys'] LOOP
                    EXECUTE format('CREATE TRIGGER ai_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION intake_history_append_only()',target);
                    IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN EXECUTE format('REVOKE TRUNCATE,DELETE ON %I FROM holoul_app',target); END IF;
                END LOOP;
                IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN REVOKE UPDATE ON ai_command_keys FROM holoul_app; END IF;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN IF EXISTS(SELECT 1 FROM ai_runs) THEN RAISE EXCEPTION 'Retained AI history requires a forward migration.'; END IF; END; $$;
            DROP TABLE ai_command_keys,ai_provider_attempts,ai_suggestions,ai_runs,ai_budget_days;
            DROP FUNCTION ai_preserve_run(),ai_preserve_suggestion(),ai_guard_result(),ai_guard_attempt(),ai_guard_source_insert();
            SQL);
    }
};
