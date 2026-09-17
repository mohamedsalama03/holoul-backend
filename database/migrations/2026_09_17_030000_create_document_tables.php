<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE document_quotas (
                customer_id uuid PRIMARY KEY REFERENCES customers(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp()
            );
            CREATE TABLE documents (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                customer_id uuid NOT NULL,
                customer_user_id uuid NOT NULL,
                parent_id uuid NOT NULL,
                uploader_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                reservation_key_hash char(64) NOT NULL CHECK (reservation_key_hash ~ '^[a-f0-9]{64}$'),
                reservation_input_hash char(64) NOT NULL CHECK (reservation_input_hash ~ '^[a-f0-9]{64}$'),
                display_name varchar(200) NOT NULL CHECK (display_name <> '' AND display_name !~ '[[:cntrl:]/\\]'),
                format varchar(4) NOT NULL CHECK (format IN ('pdf','docx')),
                expected_size integer NOT NULL CHECK (expected_size BETWEEN 1 AND 10485760),
                expected_sha256 char(64) NOT NULL CHECK (expected_sha256 ~ '^[a-f0-9]{64}$'),
                storage_key varchar(200) NOT NULL UNIQUE,
                storage_version varchar(1024),
                state varchar(16) NOT NULL DEFAULT 'uploading' CHECK (state IN ('uploading','quarantined','available','rejected','deleting','deleted')),
                upload_expires_at timestamptz NOT NULL,
                uploaded_at timestamptz,
                scan_operation_id uuid UNIQUE REFERENCES async_operations(id) ON DELETE RESTRICT,
                delete_operation_id uuid UNIQUE REFERENCES async_operations(id) ON DELETE RESTRICT,
                scan_generation integer NOT NULL DEFAULT 0 CHECK (scan_generation BETWEEN 0 AND 3),
                manual_scan_retries integer NOT NULL DEFAULT 0 CHECK (manual_scan_retries BETWEEN 0 AND 2),
                failure_code varchar(40) CHECK (failure_code IS NULL OR failure_code ~ '^[a-z][a-z0-9_]{0,39}$'),
                available_at timestamptz,
                deleted_at timestamptz,
                lock_version bigint NOT NULL DEFAULT 1 CHECK (lock_version >= 1),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY (customer_id,customer_user_id) REFERENCES customers(id,user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                UNIQUE (id,customer_id,parent_id),
                UNIQUE (uploader_id,parent_id,reservation_key_hash),
                CHECK (storage_key = 'quarantine/' || id::text),
                CHECK (upload_expires_at > created_at),
                CHECK ((storage_version IS NULL) = (uploaded_at IS NULL)),
                CHECK (storage_version IS NULL OR length(storage_version) BETWEEN 1 AND 1024),
                CHECK (state NOT IN ('quarantined','available','rejected') OR storage_version IS NOT NULL),
                CHECK ((state = 'available') = (available_at IS NOT NULL)),
                CHECK ((state = 'deleted') = (deleted_at IS NOT NULL)),
                CHECK (state <> 'uploading' OR storage_version IS NULL),
                CHECK (state NOT IN ('quarantined','available','rejected') OR scan_operation_id IS NOT NULL)
            );
            CREATE INDEX documents_owner_quota_idx ON documents(customer_id,state);
            CREATE INDEX documents_reconcile_idx ON documents(state,created_at,id);
            CREATE TABLE document_orphan_objects (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                storage_key varchar(200) NOT NULL,
                storage_version varchar(1024) NOT NULL CHECK (length(storage_version) > 0),
                object_modified_at timestamptz NOT NULL,
                state varchar(12) NOT NULL CHECK (state IN ('deleting','deleted','retained')),
                operation_id uuid UNIQUE REFERENCES async_operations(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(storage_key,storage_version)
            );
            CREATE INDEX document_orphans_pending_idx ON document_orphan_objects(state,created_at,id);
            CREATE TABLE document_reconciliation_cursors (
                id smallint PRIMARY KEY CHECK (id=1),
                document_cursor uuid,
                cursor text CHECK (cursor IS NULL OR octet_length(cursor) <= 8192),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp()
            );
            INSERT INTO document_reconciliation_cursors(id) VALUES (1);

            CREATE OR REPLACE FUNCTION document_guard_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'UPDATE' THEN
                    RAISE EXCEPTION 'Document tombstones are retained.' USING ERRCODE='55000';
                END IF;
                IF (NEW.id,NEW.customer_id,NEW.customer_user_id,NEW.parent_id,NEW.uploader_id,
                    NEW.reservation_key_hash,NEW.reservation_input_hash,NEW.display_name,NEW.format,
                    NEW.expected_size,NEW.expected_sha256,NEW.storage_key,NEW.upload_expires_at,NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.id,OLD.customer_id,OLD.customer_user_id,OLD.parent_id,OLD.uploader_id,
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
                IF NEW.lock_version < OLD.lock_version OR NEW.manual_scan_retries < OLD.manual_scan_retries
                   OR NEW.scan_generation < OLD.scan_generation THEN
                    RAISE EXCEPTION 'Document generations cannot move backwards.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER documents_guard BEFORE UPDATE OR DELETE ON documents FOR EACH ROW EXECUTE FUNCTION document_guard_mutation();
            CREATE TRIGGER documents_no_truncate BEFORE TRUNCATE ON documents FOR EACH STATEMENT EXECUTE FUNCTION document_guard_mutation();
            DO $$ BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN
                    GRANT SELECT,INSERT,UPDATE ON documents,document_quotas,document_orphan_objects,document_reconciliation_cursors TO holoul_app;
                    REVOKE DELETE,TRUNCATE ON documents,document_quotas,document_orphan_objects,document_reconciliation_cursors FROM holoul_app;
                END IF;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS document_reconciliation_cursors,document_orphan_objects,documents,document_quotas; DROP FUNCTION IF EXISTS document_guard_mutation()');
    }
};
