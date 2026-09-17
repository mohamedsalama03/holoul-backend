<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE request_drafts ADD CONSTRAINT intake_draft_document_owner_unique UNIQUE(id,request_id,customer_id);
            ALTER TABLE request_revisions ADD CONSTRAINT intake_revision_document_owner_unique UNIQUE(id,request_id,customer_id);
            ALTER TABLE request_revisions ADD COLUMN attachment_creation_xid xid8;
            ALTER TABLE document_reconciliation_cursors ADD COLUMN intake_upload_cursor uuid;
            CREATE OR REPLACE FUNCTION intake_mark_document_revision() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- Top-level xid8 survives nested savepoints and transaction-ID
                -- epochs. Historical rows remain NULL; client input is ignored.
                NEW.attachment_creation_xid := pg_current_xact_id();
                RETURN NEW;
            END; $$;
            CREATE TRIGGER intake_revision_attachment_transaction BEFORE INSERT ON request_revisions
                FOR EACH ROW EXECUTE FUNCTION intake_mark_document_revision();
            CREATE TABLE intake_draft_documents (
                draft_id uuid PRIMARY KEY,
                request_id uuid NOT NULL,
                customer_id uuid NOT NULL,
                document_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY(draft_id,request_id,customer_id) REFERENCES request_drafts(id,request_id,customer_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY(document_id,customer_id,request_id) REFERENCES documents(id,customer_id,parent_id) ON DELETE RESTRICT ON UPDATE RESTRICT
            );
            CREATE TABLE intake_revision_documents (
                revision_id uuid PRIMARY KEY,
                request_id uuid NOT NULL,
                customer_id uuid NOT NULL,
                document_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY(revision_id,request_id,customer_id) REFERENCES request_revisions(id,request_id,customer_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY(document_id,customer_id,request_id) REFERENCES documents(id,customer_id,parent_id) ON DELETE RESTRICT ON UPDATE RESTRICT
            );
            CREATE INDEX intake_draft_documents_document_idx ON intake_draft_documents(document_id);
            CREATE INDEX intake_revision_documents_document_idx ON intake_revision_documents(document_id);
            CREATE OR REPLACE FUNCTION intake_guard_retained_document() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.state IN ('deleting','deleted') AND (
                    EXISTS(SELECT 1 FROM intake_draft_documents WHERE document_id=NEW.id)
                    OR EXISTS(SELECT 1 FROM intake_revision_documents WHERE document_id=NEW.id)) THEN
                    RAISE EXCEPTION 'Referenced documents cannot be deleted.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER intake_retained_document_guard BEFORE UPDATE ON documents
                FOR EACH ROW EXECUTE FUNCTION intake_guard_retained_document();
            CREATE OR REPLACE FUNCTION intake_guard_document_attachment() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE document_state text;
            BEGIN
                IF TG_OP='UPDATE' THEN
                    RAISE EXCEPTION 'Replace an attachment by inserting a new slot.' USING ERRCODE='23514';
                END IF;
                IF TG_OP='DELETE' THEN
                    PERFORM 1 FROM request_drafts WHERE id=OLD.draft_id AND is_open FOR UPDATE;
                    IF NOT FOUND THEN
                        -- A terminal transition can close an unsubmitted
                        -- amendment. Expire only its abandoned reservation;
                        -- finalized bytes and every revision stay retained.
                        PERFORM 1 FROM documents WHERE id=OLD.document_id AND state='uploading'
                            AND upload_expires_at < clock_timestamp()
                            AND created_at <= clock_timestamp() - interval '24 hours'
                            AND NOT EXISTS(SELECT 1 FROM intake_revision_documents WHERE document_id=OLD.document_id)
                            FOR UPDATE;
                        IF NOT FOUND THEN
                            RAISE EXCEPTION 'Closed draft attachment is immutable.' USING ERRCODE='23514';
                        END IF;
                    END IF;
                    RETURN OLD;
                END IF;
                SELECT state INTO document_state FROM documents WHERE id=NEW.document_id FOR UPDATE;
                IF document_state IS NULL OR document_state IN ('deleting','deleted') THEN
                    RAISE EXCEPTION 'Document cannot be attached.' USING ERRCODE='23514';
                END IF;
                IF TG_TABLE_NAME='intake_draft_documents' THEN
                    PERFORM 1 FROM request_drafts WHERE id=NEW.draft_id AND is_open FOR UPDATE;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Draft is not editable.' USING ERRCODE='23514';
                    END IF;
                ELSE
                    IF document_state NOT IN ('quarantined','available') THEN
                        RAISE EXCEPTION 'Document upload is not complete.' USING ERRCODE='23514';
                    END IF;
                    PERFORM 1 FROM request_revisions WHERE id=NEW.revision_id AND attachment_creation_xid=pg_current_xact_id();
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Historical revision cannot acquire attachments.' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER intake_draft_document_guard BEFORE INSERT OR UPDATE OR DELETE ON intake_draft_documents
                FOR EACH ROW EXECUTE FUNCTION intake_guard_document_attachment();
            CREATE TRIGGER intake_revision_document_guard BEFORE INSERT ON intake_revision_documents
                FOR EACH ROW EXECUTE FUNCTION intake_guard_document_attachment();
            CREATE TRIGGER intake_revision_document_no_change BEFORE UPDATE OR DELETE ON intake_revision_documents
                FOR EACH ROW EXECUTE FUNCTION intake_history_append_only();
            CREATE TRIGGER intake_revision_document_no_truncate BEFORE TRUNCATE ON intake_revision_documents
                FOR EACH STATEMENT EXECUTE FUNCTION intake_history_append_only();
            DO $$ BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN
                    REVOKE UPDATE,DELETE,TRUNCATE ON intake_revision_documents FROM holoul_app;
                    GRANT SELECT,INSERT ON intake_revision_documents TO holoul_app;
                    REVOKE UPDATE,TRUNCATE ON intake_draft_documents FROM holoul_app;
                END IF;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER intake_retained_document_guard ON documents;
            DROP FUNCTION intake_guard_retained_document();
            DROP TABLE intake_revision_documents,intake_draft_documents;
            DROP TRIGGER intake_revision_attachment_transaction ON request_revisions;
            DROP FUNCTION intake_guard_document_attachment(),intake_mark_document_revision();
            ALTER TABLE request_revisions DROP COLUMN attachment_creation_xid;
            ALTER TABLE document_reconciliation_cursors DROP COLUMN intake_upload_cursor;
            ALTER TABLE request_drafts DROP CONSTRAINT intake_draft_document_owner_unique;
            ALTER TABLE request_revisions DROP CONSTRAINT intake_revision_document_owner_unique;
            SQL);
    }
};
