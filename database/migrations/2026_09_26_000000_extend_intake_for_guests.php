<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE project_requests ADD COLUMN guest_origin boolean NOT NULL DEFAULT false;
            ALTER TABLE project_requests ALTER COLUMN customer_id DROP NOT NULL, ALTER COLUMN customer_user_id DROP NOT NULL;
            ALTER TABLE project_requests ADD CONSTRAINT intake_ownership_mode CHECK
                ((customer_id IS NOT NULL AND customer_user_id IS NOT NULL) OR
                 (guest_origin AND customer_id IS NULL AND customer_user_id IS NULL));
            ALTER TABLE project_requests ADD CONSTRAINT intake_unclaimed_state CHECK
                (customer_id IS NOT NULL OR state IN ('draft','submitted','under_review','rejected','withdrawn'));
            CREATE TABLE intake_guest_access (
                request_id uuid PRIMARY KEY REFERENCES project_requests(id) ON DELETE RESTRICT,
                capability_hash char(64) NOT NULL UNIQUE CHECK (capability_hash ~ '^[a-f0-9]{64}$'),
                session_hash char(64) NOT NULL CHECK (session_hash ~ '^[a-f0-9]{64}$'),
                expires_at timestamptz NOT NULL,
                submission_key_hash char(64) CHECK (submission_key_hash ~ '^[a-f0-9]{64}$'),
                submission_input_hash char(64) CHECK (submission_input_hash ~ '^[a-f0-9]{64}$'),
                result_version bigint CHECK (result_version > 0),
                claim_token_hash char(64) UNIQUE CHECK (claim_token_hash ~ '^[a-f0-9]{64}$'),
                claim_expires_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(request_id,claim_token_hash),
                UNIQUE(session_hash,submission_key_hash),
                CHECK (expires_at > created_at AND expires_at <= created_at + interval '31 minutes'),
                CHECK ((submission_key_hash IS NULL AND submission_input_hash IS NULL AND result_version IS NULL AND claim_token_hash IS NULL AND claim_expires_at IS NULL)
                    OR (submission_key_hash IS NOT NULL AND submission_input_hash IS NOT NULL AND result_version IS NOT NULL AND claim_token_hash IS NOT NULL AND claim_expires_at IS NOT NULL))
            );
            CREATE TABLE intake_guest_claims (
                request_id uuid PRIMARY KEY REFERENCES project_requests(id) ON DELETE RESTRICT,
                customer_id uuid NOT NULL,
                customer_user_id uuid NOT NULL,
                token_hash char(64) NOT NULL UNIQUE CHECK (token_hash ~ '^[a-f0-9]{64}$'),
                key_hash char(64) NOT NULL CHECK (key_hash ~ '^[a-f0-9]{64}$'),
                result_version bigint NOT NULL CHECK (result_version > 0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                creation_xid xid8 NOT NULL,
                UNIQUE(customer_user_id,key_hash),
                FOREIGN KEY(request_id,token_hash) REFERENCES intake_guest_access(request_id,claim_token_hash) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY(customer_id,customer_user_id) REFERENCES customers(id,user_id) ON DELETE RESTRICT
            );
            CREATE OR REPLACE FUNCTION intake_stamp_claim() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                NEW.creation_xid := pg_current_xact_id();
                PERFORM 1 FROM project_requests WHERE id=NEW.request_id AND guest_origin AND customer_id IS NULL
                    AND state IN ('submitted','under_review') FOR UPDATE;
                IF NOT FOUND THEN RAISE EXCEPTION 'Not claimable.' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER intake_claim_stamp BEFORE INSERT ON intake_guest_claims FOR EACH ROW EXECUTE FUNCTION intake_stamp_claim();
            CREATE TRIGGER intake_claim_immutable BEFORE UPDATE OR DELETE ON intake_guest_claims FOR EACH ROW EXECUTE FUNCTION intake_history_append_only();
            CREATE TRIGGER intake_claim_no_truncate BEFORE TRUNCATE ON intake_guest_claims FOR EACH STATEMENT EXECUTE FUNCTION intake_history_append_only();
            CREATE OR REPLACE FUNCTION intake_check_claim_commit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM 1 FROM project_requests WHERE id=NEW.request_id AND guest_origin
                    AND customer_id=NEW.customer_id AND customer_user_id=NEW.customer_user_id;
                IF NOT FOUND THEN RAISE EXCEPTION 'Claim and ownership must commit together.' USING ERRCODE='23514'; END IF;
                IF EXISTS(SELECT 1 FROM documents WHERE parent_id=NEW.request_id AND customer_id IS DISTINCT FROM NEW.customer_id)
                    OR EXISTS(SELECT 1 FROM request_drafts WHERE request_id=NEW.request_id AND customer_id IS DISTINCT FROM NEW.customer_id) THEN
                    RAISE EXCEPTION 'Claim must include draft and document ownership.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE CONSTRAINT TRIGGER intake_claim_commit AFTER INSERT ON intake_guest_claims
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION intake_check_claim_commit();
            CREATE OR REPLACE FUNCTION intake_preserve_request_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.guest_origin IS DISTINCT FROM OLD.guest_origin
                    OR (OLD.reference IS NOT NULL AND NEW.reference IS DISTINCT FROM OLD.reference)
                    OR (OLD.submitted_at IS NOT NULL AND NEW.submitted_at IS DISTINCT FROM OLD.submitted_at) THEN
                    RAISE EXCEPTION 'Request origin and first submission are immutable.' USING ERRCODE='23514';
                END IF;
                IF (NEW.customer_id,NEW.customer_user_id) IS DISTINCT FROM (OLD.customer_id,OLD.customer_user_id) THEN
                    IF NOT (OLD.guest_origin AND OLD.customer_id IS NULL AND OLD.customer_user_id IS NULL
                        AND NEW.customer_id IS NOT NULL AND NEW.customer_user_id IS NOT NULL
                        AND EXISTS(SELECT 1 FROM intake_guest_claims WHERE request_id=OLD.id AND customer_id=NEW.customer_id
                            AND customer_user_id=NEW.customer_user_id AND creation_xid=pg_current_xact_id())) THEN
                        RAISE EXCEPTION 'Request ownership is immutable except its one-time guest claim.' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE OR REPLACE FUNCTION intake_guest_creation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.guest_origin AND NEW.customer_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Guest origin must start unclaimed.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER intake_guest_creation_guard BEFORE INSERT ON project_requests FOR EACH ROW EXECUTE FUNCTION intake_guest_creation_guard();
            CREATE OR REPLACE FUNCTION intake_guest_parent_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent_guest boolean; parent_customer uuid;
            BEGIN
                SELECT guest_origin,customer_id INTO parent_guest,parent_customer FROM project_requests WHERE id=NEW.request_id FOR KEY SHARE;
                -- Non-NULL owners retain their original composite FK enforcement/error codes.
                -- Only the nullable guest branch needs this additional parent-owner check.
                IF NOT FOUND OR (NEW.customer_id IS NULL AND (NOT parent_guest OR parent_customer IS NOT NULL)) THEN
                    RAISE EXCEPTION 'Unowned child requires an unclaimed canonical guest parent.' USING ERRCODE='23514';
                END IF;
                IF TG_TABLE_NAME='request_revisions' AND NEW.customer_id IS NULL THEN
                    IF NEW.provenance<>'guest_submission' OR NEW.submitted_by IS NOT NULL OR parent_customer IS NOT NULL OR NEW.revision_number<>1 THEN
                        RAISE EXCEPTION 'Only the original unclaimed guest submission has no author.' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            ALTER TABLE request_drafts ALTER COLUMN customer_id DROP NOT NULL;
            ALTER TABLE request_revisions ALTER COLUMN customer_id DROP NOT NULL, ALTER COLUMN submitted_by DROP NOT NULL;
            ALTER TABLE request_drafts ADD CONSTRAINT intake_draft_parent_fk FOREIGN KEY(request_id) REFERENCES project_requests(id) ON DELETE RESTRICT;
            ALTER TABLE request_revisions ADD CONSTRAINT intake_revision_parent_fk FOREIGN KEY(request_id) REFERENCES project_requests(id) ON DELETE RESTRICT;
            ALTER TABLE request_revisions DROP CONSTRAINT request_revisions_provenance_check;
            ALTER TABLE request_revisions ADD CONSTRAINT request_revisions_provenance_check CHECK
                ((provenance IN ('customer_submission','customer_amendment') AND customer_id IS NOT NULL AND submitted_by IS NOT NULL)
                    OR (provenance='guest_submission' AND customer_id IS NULL AND submitted_by IS NULL));
            CREATE TRIGGER intake_guest_draft_guard BEFORE INSERT OR UPDATE ON request_drafts FOR EACH ROW EXECUTE FUNCTION intake_guest_parent_guard();
            CREATE TRIGGER intake_guest_revision_guard BEFORE INSERT ON request_revisions FOR EACH ROW EXECUTE FUNCTION intake_guest_parent_guard();
            CREATE OR REPLACE FUNCTION intake_preserve_draft_owner() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.request_id IS DISTINCT FROM OLD.request_id OR
                    (NEW.customer_id IS DISTINCT FROM OLD.customer_id AND NOT (OLD.customer_id IS NULL AND NEW.customer_id IS NOT NULL
                        AND EXISTS(SELECT 1 FROM intake_guest_claims WHERE request_id=OLD.request_id AND customer_id=NEW.customer_id AND creation_xid=pg_current_xact_id()))) THEN
                    RAISE EXCEPTION 'Draft ownership is immutable except its one-time guest claim.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            ALTER TABLE request_state_changes DROP CONSTRAINT commercial_history_system_actor;
            ALTER TABLE request_state_changes ADD CONSTRAINT commercial_history_system_actor CHECK
                (actor_id IS NOT NULL OR (from_state='proposal' AND to_state='discovery' AND entity_version IS NOT NULL AND correlation_id IS NOT NULL)
                    OR (from_state='draft' AND to_state='submitted' AND entity_version IS NOT NULL AND correlation_id IS NOT NULL));
            CREATE OR REPLACE FUNCTION intake_guest_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.actor_id IS NULL AND NEW.from_state='draft' THEN
                    PERFORM 1 FROM project_requests WHERE id=NEW.request_id AND guest_origin AND customer_id IS NULL;
                    IF NOT FOUND THEN RAISE EXCEPTION 'Anonymous submission requires guest origin.' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER intake_guest_history_guard BEFORE INSERT ON request_state_changes FOR EACH ROW EXECUTE FUNCTION intake_guest_history_guard();

            ALTER TABLE documents ADD COLUMN guest_request_id uuid REFERENCES project_requests(id) ON DELETE RESTRICT;
            ALTER TABLE documents ALTER COLUMN customer_id DROP NOT NULL, ALTER COLUMN customer_user_id DROP NOT NULL, ALTER COLUMN uploader_id DROP NOT NULL;
            ALTER TABLE documents ADD CONSTRAINT documents_guest_owner CHECK
                ((customer_id IS NOT NULL AND customer_user_id IS NOT NULL AND (uploader_id IS NOT NULL OR guest_request_id IS NOT NULL))
                    OR (guest_request_id IS NOT NULL AND customer_id IS NULL AND customer_user_id IS NULL AND uploader_id IS NULL));
            ALTER TABLE documents ADD CONSTRAINT documents_guest_parent CHECK (guest_request_id IS NULL OR guest_request_id=parent_id);
            ALTER TABLE documents ADD CONSTRAINT documents_parent_identity UNIQUE(id,parent_id);
            CREATE UNIQUE INDEX documents_guest_reservation_unique ON documents(parent_id,reservation_key_hash) WHERE guest_request_id IS NOT NULL;
            CREATE INDEX documents_guest_parent_idx ON documents(guest_request_id,id) WHERE guest_request_id IS NOT NULL;
            CREATE INDEX intake_guest_abandoned_idx ON project_requests(created_at,id) WHERE guest_origin AND customer_id IS NULL AND state='draft';
            CREATE INDEX intake_guest_claim_customer_idx ON intake_guest_claims(customer_id);
            CREATE OR REPLACE FUNCTION document_guest_owner_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='UPDATE' AND NEW.guest_request_id IS DISTINCT FROM OLD.guest_request_id THEN
                    RAISE EXCEPTION 'Document guest origin is immutable.' USING ERRCODE='23514';
                END IF;
                IF NEW.guest_request_id IS NOT NULL THEN
                    PERFORM 1 FROM project_requests WHERE id=NEW.guest_request_id AND guest_origin
                        AND customer_id IS NOT DISTINCT FROM NEW.customer_id AND customer_user_id IS NOT DISTINCT FROM NEW.customer_user_id;
                    IF NOT FOUND THEN RAISE EXCEPTION 'Document owner must match its canonical guest parent.' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER documents_guest_owner_guard BEFORE INSERT OR UPDATE ON documents FOR EACH ROW EXECUTE FUNCTION document_guest_owner_guard();
            CREATE OR REPLACE FUNCTION document_guard_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'Document tombstones are retained.' USING ERRCODE='55000'; END IF;
                IF (NEW.customer_id,NEW.customer_user_id) IS DISTINCT FROM (OLD.customer_id,OLD.customer_user_id)
                    AND NOT (OLD.guest_request_id IS NOT NULL AND OLD.customer_id IS NULL AND OLD.customer_user_id IS NULL
                        AND NEW.customer_id IS NOT NULL AND NEW.customer_user_id IS NOT NULL
                        AND EXISTS(SELECT 1 FROM intake_guest_claims WHERE request_id=OLD.parent_id AND customer_id=NEW.customer_id
                            AND customer_user_id=NEW.customer_user_id AND creation_xid=pg_current_xact_id())) THEN
                    RAISE EXCEPTION 'Document ownership is immutable except its one-time guest claim.' USING ERRCODE='23514';
                END IF;
                IF (NEW.id,NEW.parent_id,NEW.uploader_id,NEW.reservation_key_hash,NEW.reservation_input_hash,NEW.display_name,NEW.format,
                    NEW.expected_size,NEW.expected_sha256,NEW.storage_key,NEW.upload_expires_at,NEW.created_at)
                    IS DISTINCT FROM (OLD.id,OLD.parent_id,OLD.uploader_id,OLD.reservation_key_hash,OLD.reservation_input_hash,OLD.display_name,OLD.format,
                    OLD.expected_size,OLD.expected_sha256,OLD.storage_key,OLD.upload_expires_at,OLD.created_at)
                    OR (OLD.storage_version IS NOT NULL AND (NEW.storage_version,NEW.uploaded_at) IS DISTINCT FROM (OLD.storage_version,OLD.uploaded_at)) THEN
                    RAISE EXCEPTION 'Document identity and stored version are immutable.' USING ERRCODE='23514';
                END IF;
                IF NEW.state IS DISTINCT FROM OLD.state AND NOT (
                    (OLD.state='uploading' AND NEW.state IN ('quarantined','deleting')) OR
                    (OLD.state='quarantined' AND NEW.state IN ('available','rejected','deleting')) OR
                    (OLD.state IN ('available','rejected') AND NEW.state='deleting') OR
                    (OLD.state='deleting' AND NEW.state='deleted')) THEN
                    RAISE EXCEPTION 'Invalid document transition.' USING ERRCODE='23514';
                END IF;
                IF NEW.lock_version < OLD.lock_version OR NEW.manual_scan_retries < OLD.manual_scan_retries OR NEW.scan_generation < OLD.scan_generation THEN
                    RAISE EXCEPTION 'Document generations cannot move backwards.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            ALTER TABLE request_drafts ADD CONSTRAINT intake_draft_parent_identity UNIQUE(id,request_id);
            ALTER TABLE intake_draft_documents ALTER COLUMN customer_id DROP NOT NULL;
            ALTER TABLE intake_revision_documents ALTER COLUMN customer_id DROP NOT NULL;
            ALTER TABLE intake_draft_documents ADD CONSTRAINT intake_draft_attachment_parent FOREIGN KEY(draft_id,request_id) REFERENCES request_drafts(id,request_id) ON DELETE RESTRICT;
            ALTER TABLE intake_revision_documents ADD CONSTRAINT intake_revision_attachment_parent FOREIGN KEY(request_id,revision_id) REFERENCES request_revisions(request_id,id) ON DELETE RESTRICT;
            ALTER TABLE intake_draft_documents ADD CONSTRAINT intake_draft_attachment_document FOREIGN KEY(document_id,request_id) REFERENCES documents(id,parent_id) ON DELETE RESTRICT;
            ALTER TABLE intake_revision_documents ADD CONSTRAINT intake_revision_attachment_document FOREIGN KEY(document_id,request_id) REFERENCES documents(id,parent_id) ON DELETE RESTRICT;
            CREATE TRIGGER intake_guest_draft_attachment_guard BEFORE INSERT ON intake_draft_documents FOR EACH ROW EXECUTE FUNCTION intake_guest_parent_guard();
            CREATE TRIGGER intake_guest_revision_attachment_guard BEFORE INSERT ON intake_revision_documents FOR EACH ROW EXECUTE FUNCTION intake_guest_parent_guard();
            DO $$ BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN
                    GRANT SELECT,INSERT,UPDATE ON intake_guest_access TO holoul_app;
                    REVOKE DELETE,TRUNCATE ON intake_guest_access FROM holoul_app;
                    GRANT SELECT,INSERT ON intake_guest_claims TO holoul_app;
                    REVOKE UPDATE,DELETE,TRUNCATE ON intake_guest_claims FROM holoul_app;
                END IF;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        // Never remove guest history. An unused extension can restore the exact prior guards.
        DB::unprepared(<<<'SQL'
            LOCK TABLE project_requests,documents IN ACCESS EXCLUSIVE MODE;
            DO $$ BEGIN
                IF EXISTS(SELECT 1 FROM project_requests WHERE guest_origin) THEN
                    RAISE EXCEPTION 'Retained guest history requires a forward migration or verified backup restore.' USING ERRCODE='55000';
                END IF;
            END; $$;
            DROP TRIGGER intake_guest_creation_guard ON project_requests;
            DROP TRIGGER intake_guest_draft_guard ON request_drafts;
            DROP TRIGGER intake_guest_revision_guard ON request_revisions;
            DROP TRIGGER intake_guest_history_guard ON request_state_changes;
            DROP TRIGGER documents_guest_owner_guard ON documents;
            DROP TRIGGER intake_guest_draft_attachment_guard ON intake_draft_documents;
            DROP TRIGGER intake_guest_revision_attachment_guard ON intake_revision_documents;
            DROP TABLE intake_guest_claims,intake_guest_access;
            DROP FUNCTION intake_stamp_claim(),intake_check_claim_commit(),intake_guest_creation_guard(),intake_guest_parent_guard(),intake_guest_history_guard(),document_guest_owner_guard();
            ALTER TABLE intake_draft_documents DROP CONSTRAINT intake_draft_attachment_parent, DROP CONSTRAINT intake_draft_attachment_document, ALTER COLUMN customer_id SET NOT NULL;
            ALTER TABLE intake_revision_documents DROP CONSTRAINT intake_revision_attachment_parent, DROP CONSTRAINT intake_revision_attachment_document, ALTER COLUMN customer_id SET NOT NULL;
            ALTER TABLE request_drafts DROP CONSTRAINT intake_draft_parent_identity, DROP CONSTRAINT intake_draft_parent_fk, ALTER COLUMN customer_id SET NOT NULL;
            ALTER TABLE request_revisions DROP CONSTRAINT intake_revision_parent_fk, ALTER COLUMN customer_id SET NOT NULL, ALTER COLUMN submitted_by SET NOT NULL;
            ALTER TABLE request_revisions DROP CONSTRAINT request_revisions_provenance_check;
            ALTER TABLE request_revisions ADD CONSTRAINT request_revisions_provenance_check CHECK (provenance IN ('customer_submission','customer_amendment'));
            ALTER TABLE request_state_changes DROP CONSTRAINT commercial_history_system_actor;
            ALTER TABLE request_state_changes ADD CONSTRAINT commercial_history_system_actor CHECK
                (actor_id IS NOT NULL OR (from_state='proposal' AND to_state='discovery' AND entity_version IS NOT NULL AND correlation_id IS NOT NULL));
            ALTER TABLE documents DROP CONSTRAINT documents_parent_identity, DROP CONSTRAINT documents_guest_owner, DROP CONSTRAINT documents_guest_parent;
            DROP INDEX documents_guest_reservation_unique,documents_guest_parent_idx,intake_guest_abandoned_idx;
            ALTER TABLE documents DROP COLUMN guest_request_id, ALTER COLUMN customer_id SET NOT NULL, ALTER COLUMN customer_user_id SET NOT NULL, ALTER COLUMN uploader_id SET NOT NULL;
            ALTER TABLE project_requests DROP CONSTRAINT intake_ownership_mode, DROP CONSTRAINT intake_unclaimed_state, DROP COLUMN guest_origin,
                ALTER COLUMN customer_id SET NOT NULL, ALTER COLUMN customer_user_id SET NOT NULL;
            CREATE OR REPLACE FUNCTION intake_preserve_request_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.customer_id IS DISTINCT FROM OLD.customer_id OR NEW.customer_user_id IS DISTINCT FROM OLD.customer_user_id
                    OR (OLD.reference IS NOT NULL AND NEW.reference IS DISTINCT FROM OLD.reference)
                    OR (OLD.submitted_at IS NOT NULL AND NEW.submitted_at IS DISTINCT FROM OLD.submitted_at) THEN
                    RAISE EXCEPTION 'Request ownership and first submission are immutable.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE OR REPLACE FUNCTION intake_preserve_draft_owner() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.request_id IS DISTINCT FROM OLD.request_id OR NEW.customer_id IS DISTINCT FROM OLD.customer_id THEN
                    RAISE EXCEPTION 'Draft ownership is immutable.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE OR REPLACE FUNCTION document_guard_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'Document tombstones are retained.' USING ERRCODE='55000'; END IF;
                IF (NEW.id,NEW.customer_id,NEW.customer_user_id,NEW.parent_id,NEW.uploader_id,
                    NEW.reservation_key_hash,NEW.reservation_input_hash,NEW.display_name,NEW.format,
                    NEW.expected_size,NEW.expected_sha256,NEW.storage_key,NEW.upload_expires_at,NEW.created_at)
                    IS DISTINCT FROM (OLD.id,OLD.customer_id,OLD.customer_user_id,OLD.parent_id,OLD.uploader_id,
                    OLD.reservation_key_hash,OLD.reservation_input_hash,OLD.display_name,OLD.format,
                    OLD.expected_size,OLD.expected_sha256,OLD.storage_key,OLD.upload_expires_at,OLD.created_at)
                    OR (OLD.storage_version IS NOT NULL AND (NEW.storage_version,NEW.uploaded_at) IS DISTINCT FROM (OLD.storage_version,OLD.uploaded_at)) THEN
                    RAISE EXCEPTION 'Document identity and stored version are immutable.' USING ERRCODE='23514';
                END IF;
                IF NEW.state IS DISTINCT FROM OLD.state AND NOT (
                    (OLD.state='uploading' AND NEW.state IN ('quarantined','deleting')) OR
                    (OLD.state='quarantined' AND NEW.state IN ('available','rejected','deleting')) OR
                    (OLD.state IN ('available','rejected') AND NEW.state='deleting') OR
                    (OLD.state='deleting' AND NEW.state='deleted')) THEN
                    RAISE EXCEPTION 'Invalid document transition.' USING ERRCODE='23514';
                END IF;
                IF NEW.lock_version < OLD.lock_version OR NEW.manual_scan_retries < OLD.manual_scan_retries OR NEW.scan_generation < OLD.scan_generation THEN
                    RAISE EXCEPTION 'Document generations cannot move backwards.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            SQL);
    }
};
