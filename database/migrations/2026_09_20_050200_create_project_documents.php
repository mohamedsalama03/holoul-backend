<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE project_document_uploads (
                document_id uuid PRIMARY KEY,
                project_id uuid NOT NULL,
                customer_id uuid NOT NULL,
                visibility varchar(12) NOT NULL CHECK (visibility IN ('internal','customer')),
                attached_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY (project_id,customer_id) REFERENCES projects(id,customer_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY (document_id,customer_id,project_id) REFERENCES documents(id,customer_id,parent_id) ON DELETE RESTRICT ON UPDATE RESTRICT
            );
            CREATE INDEX project_document_uploads_parent_idx ON project_document_uploads(project_id,document_id);
            CREATE TABLE project_documents (
                document_id uuid PRIMARY KEY,
                project_id uuid NOT NULL,
                customer_id uuid NOT NULL,
                visibility varchar(12) NOT NULL CHECK (visibility IN ('internal','customer')),
                attached_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY (project_id,customer_id) REFERENCES projects(id,customer_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY (document_id,customer_id,project_id) REFERENCES documents(id,customer_id,parent_id) ON DELETE RESTRICT ON UPDATE RESTRICT
            );
            CREATE INDEX project_documents_parent_idx ON project_documents(project_id,document_id);
            ALTER TABLE document_reconciliation_cursors ADD COLUMN project_upload_cursor uuid;

            CREATE OR REPLACE FUNCTION project_document_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Project document history is retained.' USING ERRCODE='55000';
            END; $$;
            CREATE TRIGGER project_documents_no_change BEFORE UPDATE OR DELETE ON project_documents
                FOR EACH ROW EXECUTE FUNCTION project_document_append_only();
            CREATE TRIGGER project_documents_no_truncate BEFORE TRUNCATE ON project_documents
                FOR EACH STATEMENT EXECUTE FUNCTION project_document_append_only();
            CREATE TRIGGER project_document_uploads_no_truncate BEFORE TRUNCATE ON project_document_uploads
                FOR EACH STATEMENT EXECUTE FUNCTION project_document_append_only();

            CREATE OR REPLACE FUNCTION project_guard_document_attachment() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE p_state text; d_state text; d_uploader uuid;
            BEGIN
                IF TG_OP='UPDATE' THEN
                    RAISE EXCEPTION 'Project upload identity and visibility are immutable.' USING ERRCODE='23514';
                END IF;
                IF TG_OP='DELETE' THEN
                    PERFORM 1 FROM projects WHERE id=OLD.project_id FOR UPDATE;
                    SELECT state INTO d_state FROM documents WHERE id=OLD.document_id FOR UPDATE;
                    IF d_state <> 'uploading' AND NOT EXISTS (
                        SELECT 1 FROM project_documents WHERE document_id=OLD.document_id AND project_id=OLD.project_id
                        AND customer_id=OLD.customer_id AND visibility=OLD.visibility AND attached_by=OLD.attached_by
                    ) THEN
                        RAISE EXCEPTION 'Finalized project uploads require retained history.' USING ERRCODE='23514';
                    END IF;
                    RETURN OLD;
                END IF;
                SELECT state INTO p_state FROM projects WHERE id=NEW.project_id FOR UPDATE;
                IF p_state IS NULL OR p_state IN ('completed','cancelled') THEN
                    RAISE EXCEPTION 'Project does not accept documents.' USING ERRCODE='23514';
                END IF;
                SELECT state,uploader_id INTO d_state,d_uploader FROM documents WHERE id=NEW.document_id FOR UPDATE;
                IF TG_TABLE_NAME='project_document_uploads' THEN
                    IF d_state IS DISTINCT FROM 'uploading' OR d_uploader IS DISTINCT FROM NEW.attached_by
                        OR NOT EXISTS (SELECT 1 FROM users WHERE id=NEW.attached_by AND kind='staff' AND enabled) THEN
                        RAISE EXCEPTION 'Only authorized staff upload reservations can attach.' USING ERRCODE='23514';
                    END IF;
                ELSE
                    IF d_state NOT IN ('quarantined','available') OR NOT EXISTS (
                        SELECT 1 FROM project_document_uploads WHERE document_id=NEW.document_id AND project_id=NEW.project_id
                        AND customer_id=NEW.customer_id AND visibility=NEW.visibility AND attached_by=NEW.attached_by
                    ) THEN
                        RAISE EXCEPTION 'Project attachment must finalize its exact reservation.' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER project_document_uploads_guard BEFORE INSERT OR UPDATE OR DELETE ON project_document_uploads
                FOR EACH ROW EXECUTE FUNCTION project_guard_document_attachment();
            CREATE TRIGGER project_documents_guard BEFORE INSERT ON project_documents
                FOR EACH ROW EXECUTE FUNCTION project_guard_document_attachment();
            CREATE OR REPLACE FUNCTION project_guard_retained_document() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.state IN ('deleting','deleted') AND (
                    EXISTS (SELECT 1 FROM project_document_uploads WHERE document_id=NEW.id)
                    OR EXISTS (SELECT 1 FROM project_documents WHERE document_id=NEW.id)
                ) THEN
                    RAISE EXCEPTION 'Referenced project documents cannot be deleted.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER project_retained_document_guard BEFORE UPDATE ON documents
                FOR EACH ROW EXECUTE FUNCTION project_guard_retained_document();
            DO $$ BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN
                    GRANT SELECT,INSERT,DELETE ON project_document_uploads TO holoul_app;
                    REVOKE UPDATE,TRUNCATE ON project_document_uploads FROM holoul_app;
                    GRANT SELECT,INSERT ON project_documents TO holoul_app;
                    REVOKE UPDATE,DELETE,TRUNCATE ON project_documents FROM holoul_app;
                END IF;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                IF EXISTS (SELECT 1 FROM project_documents) OR EXISTS (SELECT 1 FROM project_document_uploads) THEN
                    RAISE EXCEPTION 'Project document history prevents downgrade.' USING ERRCODE='55000';
                END IF;
            END; $$;
            DROP TRIGGER project_retained_document_guard ON documents;
            DROP TABLE project_documents,project_document_uploads;
            DROP FUNCTION project_guard_retained_document(),project_guard_document_attachment(),project_document_append_only();
            ALTER TABLE document_reconciliation_cursors DROP COLUMN project_upload_cursor;
            SQL);
    }
};
