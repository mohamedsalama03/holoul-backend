<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE SEQUENCE contact_reference_sequence AS bigint MINVALUE 1 NO CYCLE;
            CREATE TABLE contact_messages (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                reference varchar(40) NOT NULL UNIQUE CHECK(reference ~ '^CNT-[0-9]{4}-[0-9]{5,19}$'),
                full_name text, email text, phone text, company text, message text,
                status varchar(16) NOT NULL DEFAULT 'received' CHECK(status IN ('received','in_progress','resolved','spam','redacted')),
                lock_version bigint NOT NULL DEFAULT 1 CHECK(lock_version>0),
                received_at timestamptz NOT NULL, updated_at timestamptz NOT NULL, redacted_at timestamptz,
                CHECK ((status='redacted' AND redacted_at IS NOT NULL AND full_name IS NULL AND email IS NULL AND phone IS NULL AND company IS NULL AND message IS NULL)
                    OR (status<>'redacted' AND redacted_at IS NULL AND full_name IS NOT NULL AND email IS NOT NULL AND phone IS NOT NULL AND message IS NOT NULL))
            );
            ALTER SEQUENCE contact_reference_sequence OWNED BY contact_messages.reference;
            CREATE INDEX contact_messages_status_id ON contact_messages(status,id DESC);
            CREATE TABLE contact_submission_keys (
                key_hash char(64) PRIMARY KEY CHECK(key_hash ~ '^[0-9a-f]{64}$'),
                input_hash char(64) NOT NULL CHECK(input_hash ~ '^[0-9a-f]{64}$'),
                message_id uuid NOT NULL REFERENCES contact_messages(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                expires_at timestamptz NOT NULL
            );
            CREATE TABLE contact_deliveries (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                message_id uuid NOT NULL UNIQUE REFERENCES contact_messages(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                operation_id uuid UNIQUE REFERENCES async_operations(id) ON DELETE RESTRICT,
                state varchar(16) NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','sending','sent','uncertain','blocked','suppressed')),
                send_fence bigint, sent_at timestamptz, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CHECK ((state='sent')=(sent_at IS NOT NULL))
            );
            CREATE TABLE contact_delivery_attempts (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                delivery_id uuid NOT NULL REFERENCES contact_deliveries(id) ON DELETE RESTRICT,
                fence bigint NOT NULL CHECK(fence>0), outcome varchar(24) NOT NULL CHECK(outcome IN ('started','sent','not_accepted','uncertain','blocked','suppressed')),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(delivery_id,fence,outcome)
            );
            CREATE OR REPLACE FUNCTION contact_preserve_receipt() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP<>'UPDATE' THEN RAISE EXCEPTION 'Contact receipts cannot be removed.' USING ERRCODE='55000'; END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.reference IS DISTINCT FROM OLD.reference OR NEW.received_at IS DISTINCT FROM OLD.received_at
                    OR NEW.lock_version<>OLD.lock_version+1 OR OLD.status='redacted' THEN
                    RAISE EXCEPTION 'Contact receipt identity is immutable.' USING ERRCODE='23514';
                END IF;
                IF NEW.status<>'redacted' AND (NEW.full_name IS DISTINCT FROM OLD.full_name OR NEW.email IS DISTINCT FROM OLD.email
                    OR NEW.phone IS DISTINCT FROM OLD.phone OR NEW.company IS DISTINCT FROM OLD.company OR NEW.message IS DISTINCT FROM OLD.message) THEN
                    RAISE EXCEPTION 'Received contact content is immutable.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER contact_receipt_guard BEFORE UPDATE OR DELETE ON contact_messages FOR EACH ROW EXECUTE FUNCTION contact_preserve_receipt();
            CREATE TRIGGER contact_no_truncate BEFORE TRUNCATE ON contact_messages FOR EACH STATEMENT EXECUTE FUNCTION contact_preserve_receipt();
            CREATE OR REPLACE FUNCTION contact_attempt_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Contact delivery attempts are append only.' USING ERRCODE='55000'; END; $$;
            CREATE TRIGGER contact_attempt_guard BEFORE UPDATE OR DELETE ON contact_delivery_attempts FOR EACH ROW EXECUTE FUNCTION contact_attempt_immutable();
            CREATE TRIGGER contact_attempt_no_truncate BEFORE TRUNCATE ON contact_delivery_attempts FOR EACH STATEMENT EXECUTE FUNCTION contact_attempt_immutable();
            REVOKE ALL ON contact_messages,contact_submission_keys,contact_deliveries,contact_delivery_attempts FROM holoul_app;
            GRANT SELECT,INSERT,UPDATE ON contact_messages,contact_submission_keys,contact_deliveries TO holoul_app;
            GRANT SELECT,INSERT ON contact_delivery_attempts TO holoul_app;
            GRANT USAGE,SELECT ON SEQUENCE contact_reference_sequence TO holoul_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            LOCK TABLE contact_messages IN ACCESS EXCLUSIVE MODE;
            DO $$ BEGIN
                IF EXISTS(SELECT 1 FROM contact_messages) THEN
                    RAISE EXCEPTION 'Preserved contact receipts require reviewed forward recovery.' USING ERRCODE='55000';
                END IF;
            END $$;
            DROP TABLE contact_delivery_attempts,contact_deliveries,contact_submission_keys,contact_messages;
            -- The owned receipt sequence is removed with contact_messages.
            DROP FUNCTION contact_preserve_receipt(),contact_attempt_immutable();
            SQL);
    }
};
