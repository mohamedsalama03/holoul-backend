<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE notification_inboxes (
                user_id uuid PRIMARY KEY REFERENCES users(id) ON DELETE RESTRICT,
                lock_version bigint NOT NULL DEFAULT 1 CHECK(lock_version>0)
            );
            CREATE TABLE notification_preferences (
                user_id uuid PRIMARY KEY REFERENCES users(id) ON DELETE RESTRICT,
                workflow_email boolean NOT NULL DEFAULT true,
                lock_version bigint NOT NULL DEFAULT 1 CHECK(lock_version>0)
            );
            CREATE TABLE notifications (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                recipient_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                type varchar(64) NOT NULL CHECK(type IN ('request.submitted','request.information_required','proposal.issued','proposal.accepted','proposal.declined','project.created','project.update_published','project.milestone_updated','project.state_changed','ai.suggestion_ready','ai.failed')),
                title varchar(160) NOT NULL,
                message varchar(512) NOT NULL,
                resource_type varchar(24) NOT NULL CHECK(resource_type IN ('project_request','proposal','project','ai_run')),
                resource_id uuid NOT NULL CHECK(substring(resource_id::text,15,1)='7'),
                logical_key_hash char(64) NOT NULL CHECK(logical_key_hash ~ '^[a-f0-9]{64}$'),
                input_hash char(64) NOT NULL CHECK(input_hash ~ '^[a-f0-9]{64}$'),
                correlation_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                read_at timestamptz,
                lock_version bigint NOT NULL DEFAULT 1 CHECK(lock_version>0),
                UNIQUE(recipient_id,type,logical_key_hash),
                CHECK((read_at IS NULL AND lock_version=1) OR (read_at IS NOT NULL AND lock_version=2))
            );
            CREATE INDEX notifications_inbox ON notifications(recipient_id,id);
            CREATE INDEX notifications_unread ON notifications(recipient_id,id) WHERE read_at IS NULL;
            CREATE TABLE notification_deliveries (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                notification_id uuid NOT NULL UNIQUE REFERENCES notifications(id) ON DELETE RESTRICT,
                channel varchar(8) NOT NULL DEFAULT 'email' CHECK(channel='email'),
                state varchar(16) NOT NULL CHECK(state IN ('pending','processing','accepted','failed','uncertain','suppressed')),
                generation integer NOT NULL DEFAULT 1 CHECK(generation BETWEEN 1 AND 100),
                operation_id uuid UNIQUE REFERENCES async_operations(id) ON DELETE RESTRICT,
                send_fence bigint,
                failure_code varchar(40) CHECK(failure_code IN ('recipient_unavailable','preference_disabled','email_disabled','provider_unavailable','provider_rejected','acknowledgement_uncertain','attempts_exhausted')),
                lock_version bigint NOT NULL DEFAULT 1 CHECK(lock_version>0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                processing_at timestamptz,
                completed_at timestamptz
            );
            CREATE INDEX notification_delivery_reconcile ON notification_deliveries(state,id);
            CREATE TABLE notification_delivery_attempts (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                delivery_id uuid NOT NULL REFERENCES notification_deliveries(id) ON DELETE RESTRICT,
                operation_id uuid NOT NULL REFERENCES async_operations(id) ON DELETE RESTRICT,
                generation integer NOT NULL CHECK(generation BETWEEN 1 AND 100),
                attempt_number integer NOT NULL CHECK(attempt_number BETWEEN 1 AND 5),
                fence bigint NOT NULL CHECK(fence>0),
                state varchar(16) NOT NULL CHECK(state IN ('processing','accepted','failed','uncertain')),
                provider_reference varchar(160) CHECK(provider_reference ~ '^[A-Za-z0-9_.:@/-]{1,160}$'),
                failure_code varchar(40) CHECK(failure_code IN ('provider_unavailable','provider_rejected','acknowledgement_uncertain')),
                started_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                completed_at timestamptz,
                UNIQUE(operation_id,attempt_number), UNIQUE(delivery_id,generation,attempt_number),
                CHECK((state='processing')=(completed_at IS NULL))
            );
            CREATE TABLE notification_replays (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                delivery_id uuid NOT NULL REFERENCES notification_deliveries(id) ON DELETE RESTRICT,
                actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                generation integer NOT NULL CHECK(generation BETWEEN 2 AND 100),
                prior_state varchar(16) NOT NULL CHECK(prior_state IN ('failed','uncertain')),
                resolution varchar(32) NOT NULL CHECK(resolution IN ('retry_failure','confirmed_not_accepted')),
                reason varchar(1000) NOT NULL CHECK(char_length(btrim(reason))>0),
                correlation_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(delivery_id,generation),
                CHECK(prior_state<>'uncertain' OR resolution='confirmed_not_accepted')
            );
            CREATE TABLE notification_command_keys (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                operation varchar(24) NOT NULL CHECK(operation IN ('read','read_all','preferences_update','replay')),
                key_hash char(64) NOT NULL CHECK(key_hash ~ '^[a-f0-9]{64}$'),
                input_hash char(64) NOT NULL CHECK(input_hash ~ '^[a-f0-9]{64}$'),
                result jsonb NOT NULL CHECK(jsonb_typeof(result)='object' AND octet_length(result::text)<4096),
                expires_at timestamptz NOT NULL DEFAULT clock_timestamp()+interval '72 hours',
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(actor_id,operation,key_hash)
            );
            CREATE OR REPLACE FUNCTION notification_preserve_record() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' OR (to_jsonb(NEW)-ARRAY['read_at','lock_version']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['read_at','lock_version'])
                    OR OLD.read_at IS NOT NULL OR NEW.read_at IS NULL OR NEW.lock_version<>OLD.lock_version+1 THEN
                    RAISE EXCEPTION 'Notification history is retained.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER notification_record_guard BEFORE UPDATE OR DELETE ON notifications FOR EACH ROW EXECUTE FUNCTION notification_preserve_record();
            CREATE OR REPLACE FUNCTION notification_preserve_attempt() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' OR OLD.state<>'processing' OR NEW.state='processing'
                    OR (to_jsonb(NEW)-ARRAY['state','completed_at','failure_code','provider_reference']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','completed_at','failure_code','provider_reference']) THEN
                    RAISE EXCEPTION 'Delivery attempts are retained.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER notification_attempt_guard BEFORE UPDATE OR DELETE ON notification_delivery_attempts FOR EACH ROW EXECUTE FUNCTION notification_preserve_attempt();
            CREATE OR REPLACE FUNCTION notification_preserve_delivery() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' OR (NEW.id,NEW.notification_id,NEW.channel,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.notification_id,OLD.channel,OLD.created_at) THEN
                    RAISE EXCEPTION 'Delivery identity is retained.' USING ERRCODE='23514';
                END IF;
                IF OLD.operation_id IS NULL AND NEW.operation_id IS NOT NULL AND OLD.state='pending' AND NEW.state='pending'
                    AND NEW.generation=1 AND NEW.lock_version=OLD.lock_version THEN RETURN NEW; END IF;
                IF NEW.lock_version<>OLD.lock_version+1 OR OLD.state IN ('accepted','suppressed') THEN
                    RAISE EXCEPTION 'Terminal or stale notification delivery.' USING ERRCODE='23514';
                END IF;
                IF OLD.state IN ('failed','uncertain') THEN
                    IF NEW.state<>'pending' OR NEW.generation<>OLD.generation+1 OR NEW.operation_id=OLD.operation_id
                        OR NOT EXISTS(SELECT 1 FROM notification_replays WHERE delivery_id=OLD.id AND generation=NEW.generation AND prior_state=OLD.state) THEN
                        RAISE EXCEPTION 'Terminal delivery requires explicit retained replay.' USING ERRCODE='23514';
                    END IF;
                ELSIF NEW.generation<>OLD.generation OR NEW.operation_id IS DISTINCT FROM OLD.operation_id
                    OR NOT ((OLD.state='pending' AND NEW.state IN ('processing','failed','suppressed'))
                        OR (OLD.state='processing' AND NEW.state IN ('pending','accepted','failed','uncertain'))) THEN
                    RAISE EXCEPTION 'Invalid delivery transition.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER notification_delivery_guard BEFORE UPDATE OR DELETE ON notification_deliveries FOR EACH ROW EXECUTE FUNCTION notification_preserve_delivery();
            CREATE OR REPLACE FUNCTION notification_validate_attempt() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS(SELECT 1 FROM notification_deliveries d JOIN async_operations o ON o.id=d.operation_id
                    WHERE d.id=NEW.delivery_id AND d.generation=NEW.generation AND d.operation_id=NEW.operation_id
                    AND d.state='pending' AND o.state='running' AND o.fence=NEW.fence AND o.attempts=NEW.attempt_number
                    AND o.lease_expires_at>clock_timestamp()) THEN
                    RAISE EXCEPTION 'Attempt requires current delivery operation fence.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER notification_attempt_insert_guard BEFORE INSERT ON notification_delivery_attempts FOR EACH ROW EXECUTE FUNCTION notification_validate_attempt();
            DO $$ DECLARE target text; BEGIN
                FOREACH target IN ARRAY ARRAY['notification_replays','notification_command_keys'] LOOP
                    EXECUTE format('CREATE TRIGGER notification_history_guard BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION intake_history_append_only()',target);
                    IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN EXECUTE format('REVOKE UPDATE,DELETE ON %I FROM holoul_app',target); END IF;
                END LOOP;
                FOREACH target IN ARRAY ARRAY['notification_inboxes','notification_preferences','notifications','notification_deliveries','notification_delivery_attempts','notification_replays','notification_command_keys'] LOOP
                    EXECUTE format('CREATE TRIGGER notification_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION intake_history_append_only()',target);
                    IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN EXECUTE format('REVOKE DELETE,TRUNCATE ON %I FROM holoul_app',target); END IF;
                END LOOP;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        if (DB::table('notifications')->exists()) {
            throw new LogicException('Notification history cannot be downgraded.');
        }
        DB::unprepared('DROP TABLE notification_command_keys,notification_replays,notification_delivery_attempts,notification_deliveries,notifications,notification_preferences,notification_inboxes; DROP FUNCTION notification_preserve_record(),notification_preserve_attempt(),notification_preserve_delivery(),notification_validate_attempt();');
    }
};
