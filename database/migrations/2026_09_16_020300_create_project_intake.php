<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE customers ADD CONSTRAINT customers_id_user_unique UNIQUE (id, user_id);
            CREATE SEQUENCE IF NOT EXISTS request_reference_sequence AS bigint MINVALUE 1 NO CYCLE;
            CREATE TABLE project_requests (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                customer_id uuid NOT NULL,
                customer_user_id uuid NOT NULL,
                state varchar(32) NOT NULL DEFAULT 'draft' CHECK (state IN ('draft','submitted','under_review','information_required','discovery','rejected','withdrawn')),
                reference varchar(40) UNIQUE CHECK (reference ~ '^REQ-[0-9]{4}-[0-9]{5,19}$'),
                lock_version bigint NOT NULL DEFAULT 1 CHECK (lock_version > 0),
                latest_revision_number integer NOT NULL DEFAULT 0 CHECK (latest_revision_number >= 0),
                latest_revision_id uuid,
                assigned_staff_id uuid,
                assigned_staff_kind varchar(16) NOT NULL DEFAULT 'staff' CHECK (assigned_staff_kind='staff'),
                information_request_id uuid,
                submitted_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY (customer_id,customer_user_id) REFERENCES customers(id,user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY (assigned_staff_id,assigned_staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT ON UPDATE RESTRICT,
                UNIQUE (id,customer_id), UNIQUE (id,customer_user_id),
                CHECK ((state='draft' AND reference IS NULL AND submitted_at IS NULL AND latest_revision_number=0 AND latest_revision_id IS NULL)
                    OR (state<>'draft' AND reference IS NOT NULL AND submitted_at IS NOT NULL AND latest_revision_number>0 AND latest_revision_id IS NOT NULL)),
                CHECK ((state='information_required')=(information_request_id IS NOT NULL))
            );
            ALTER SEQUENCE request_reference_sequence OWNED BY project_requests.reference;
            CREATE TABLE request_drafts (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                request_id uuid NOT NULL UNIQUE,
                customer_id uuid NOT NULL,
                is_open boolean NOT NULL DEFAULT true,
                base_revision_number integer NOT NULL DEFAULT 0 CHECK (base_revision_number >= 0),
                category_id uuid REFERENCES categories(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                subcategory_id uuid,
                project_name varchar(200) CHECK (project_name IS NULL OR char_length(btrim(project_name)) BETWEEN 1 AND 200),
                project_description text CHECK (project_description IS NULL OR char_length(btrim(project_description)) BETWEEN 1 AND 20000),
                budget_unknown boolean,
                budget_minor bigint CHECK (budget_minor >= 0),
                currency char(3) REFERENCES currencies(code) ON DELETE RESTRICT ON UPDATE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY (request_id,customer_id) REFERENCES project_requests(id,customer_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY (subcategory_id,category_id) REFERENCES subcategories(id,category_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CHECK (subcategory_id IS NULL OR category_id IS NOT NULL),
                CHECK ((budget_unknown IS NULL AND budget_minor IS NULL AND currency IS NULL)
                    OR (budget_unknown IS TRUE AND budget_minor IS NULL AND currency IS NULL)
                    OR (budget_unknown IS FALSE AND ((budget_minor IS NULL AND currency IS NULL) OR (budget_minor IS NOT NULL AND currency IS NOT NULL))))
            );
            CREATE TABLE request_revisions (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                request_id uuid NOT NULL,
                customer_id uuid NOT NULL,
                revision_number integer NOT NULL CHECK (revision_number>0),
                full_name varchar(200) NOT NULL CHECK (char_length(btrim(full_name))>0),
                email varchar(254) NOT NULL CHECK (email=lower(btrim(email))),
                phone_e164 varchar(16) NOT NULL CHECK (phone_e164 ~ '^\+[1-9][0-9]{1,14}$'),
                category_id uuid NOT NULL REFERENCES categories(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                subcategory_id uuid NOT NULL,
                category_label varchar(160) NOT NULL CHECK (char_length(btrim(category_label))>0),
                subcategory_label varchar(160) NOT NULL CHECK (char_length(btrim(subcategory_label))>0),
                project_name varchar(200) NOT NULL CHECK (char_length(btrim(project_name))>0),
                project_description text NOT NULL CHECK (char_length(btrim(project_description)) BETWEEN 1 AND 20000),
                budget_unknown boolean NOT NULL,
                budget_minor bigint,
                currency char(3) REFERENCES currencies(code) ON DELETE RESTRICT ON UPDATE RESTRICT,
                submitted_by uuid NOT NULL,
                submitted_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                provenance varchar(32) NOT NULL CHECK (provenance IN ('customer_submission','customer_amendment')),
                UNIQUE (request_id,revision_number), UNIQUE (request_id,id), UNIQUE (request_id,id,revision_number),
                FOREIGN KEY (request_id,customer_id) REFERENCES project_requests(id,customer_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY (request_id,submitted_by) REFERENCES project_requests(id,customer_user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY (subcategory_id,category_id) REFERENCES subcategories(id,category_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CHECK ((budget_unknown=true AND budget_minor IS NULL AND currency IS NULL)
                    OR (budget_unknown=false AND budget_minor IS NOT NULL AND budget_minor>=0 AND currency IS NOT NULL))
            );
            ALTER TABLE project_requests ADD CONSTRAINT project_requests_latest_revision_fk
                FOREIGN KEY (id,latest_revision_id,latest_revision_number) REFERENCES request_revisions(request_id,id,revision_number) ON DELETE RESTRICT ON UPDATE RESTRICT;
            CREATE TABLE request_assignments (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                request_id uuid NOT NULL REFERENCES project_requests(id) ON DELETE RESTRICT,
                previous_staff_id uuid,
                assigned_staff_id uuid NOT NULL,
                staff_kind varchar(16) NOT NULL DEFAULT 'staff' CHECK (staff_kind='staff'),
                assigned_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY (previous_staff_id,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY (assigned_staff_id,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY (assigned_by,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT ON UPDATE RESTRICT
            );
            CREATE TABLE information_requests (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                request_id uuid NOT NULL,
                customer_id uuid NOT NULL,
                origin_state varchar(32) NOT NULL CHECK (origin_state='under_review'),
                question text NOT NULL CHECK (char_length(btrim(question)) BETWEEN 1 AND 5000),
                requested_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                staff_kind varchar(16) NOT NULL DEFAULT 'staff' CHECK (staff_kind='staff'),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(request_id,id), UNIQUE(id,request_id,customer_id),
                FOREIGN KEY (request_id,customer_id) REFERENCES project_requests(id,customer_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY (requested_by,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT ON UPDATE RESTRICT
            );
            ALTER TABLE project_requests ADD CONSTRAINT project_requests_information_fk
                FOREIGN KEY (id,information_request_id) REFERENCES information_requests(request_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT;
            CREATE TABLE information_responses (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                information_request_id uuid NOT NULL UNIQUE,
                request_id uuid NOT NULL,
                customer_id uuid NOT NULL,
                response text NOT NULL CHECK (char_length(btrim(response)) BETWEEN 1 AND 10000),
                responded_by uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY (information_request_id,request_id,customer_id) REFERENCES information_requests(id,request_id,customer_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY (request_id,responded_by) REFERENCES project_requests(id,customer_user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
            );
            CREATE TABLE information_resolutions (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                information_request_id uuid NOT NULL UNIQUE,
                request_id uuid NOT NULL,
                resolution varchar(16) NOT NULL CHECK (resolution IN ('acknowledged','closed')),
                resolved_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY (request_id,information_request_id) REFERENCES information_requests(request_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
            );
            CREATE TABLE request_state_changes (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                request_id uuid NOT NULL REFERENCES project_requests(id) ON DELETE RESTRICT,
                from_state varchar(32) NOT NULL CHECK (from_state IN ('draft','submitted','under_review','information_required','discovery')),
                to_state varchar(32) NOT NULL CHECK (to_state IN ('submitted','under_review','information_required','discovery','rejected','withdrawn')),
                actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                reason text CHECK (reason IS NULL OR char_length(btrim(reason)) BETWEEN 1 AND 5000),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CHECK (from_state<>to_state),
                CHECK (to_state<>'rejected' OR reason IS NOT NULL)
            );
            CREATE TABLE intake_submission_keys (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                operation varchar(32) NOT NULL DEFAULT 'intake.submit' CHECK (operation='intake.submit'),
                key_hash char(64) NOT NULL CHECK (key_hash ~ '^[a-f0-9]{64}$'),
                input_hash char(64) NOT NULL CHECK (input_hash ~ '^[a-f0-9]{64}$'),
                request_id uuid NOT NULL REFERENCES project_requests(id) ON DELETE RESTRICT,
                revision_id uuid,
                revision_number integer CHECK (revision_number>0),
                result_version bigint CHECK (result_version>0),
                result_state varchar(32) CHECK (result_state IN ('submitted','under_review','information_required')),
                reference varchar(40),
                expires_at timestamptz NOT NULL,
                UNIQUE (actor_id,operation,key_hash),
                FOREIGN KEY (request_id,actor_id) REFERENCES project_requests(id,customer_user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY (request_id,revision_id) REFERENCES request_revisions(request_id,id) ON DELETE RESTRICT
            );
            CREATE TABLE intake_notification_intents (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                request_id uuid NOT NULL,
                revision_id uuid NOT NULL,
                kind varchar(32) NOT NULL CHECK (kind IN ('intake.submitted','intake.amended')),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (revision_id,kind),
                FOREIGN KEY (request_id,revision_id) REFERENCES request_revisions(request_id,id) ON DELETE RESTRICT
            );
            CREATE INDEX intake_customer_created_idx ON project_requests(customer_id,created_at DESC,id DESC);
            CREATE INDEX intake_state_created_idx ON project_requests(state,created_at DESC,id DESC);
            CREATE INDEX intake_assignee_created_idx ON project_requests(assigned_staff_id,created_at DESC,id DESC) WHERE state<>'draft';
            CREATE INDEX intake_submitted_idx ON project_requests(submitted_at DESC,id DESC);
            CREATE INDEX intake_revisions_category_idx ON request_revisions(category_id,request_id,revision_number DESC);
            CREATE INDEX intake_drafts_search_idx ON request_drafts USING gin(to_tsvector('simple',coalesce(project_name,'')||' '||coalesce(project_description,'')));
            CREATE INDEX intake_revisions_search_idx ON request_revisions USING gin(to_tsvector('simple',project_name||' '||project_description));
            CREATE INDEX intake_assignments_request_idx ON request_assignments(request_id,created_at,id);
            CREATE INDEX intake_information_request_idx ON information_requests(request_id,created_at,id);
            CREATE INDEX intake_history_request_idx ON request_state_changes(request_id,created_at,id);
            CREATE INDEX intake_keys_expiry_idx ON intake_submission_keys(expires_at);

            CREATE OR REPLACE FUNCTION intake_preserve_request_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.customer_id IS DISTINCT FROM OLD.customer_id OR NEW.customer_user_id IS DISTINCT FROM OLD.customer_user_id
                    OR (OLD.reference IS NOT NULL AND NEW.reference IS DISTINCT FROM OLD.reference)
                    OR (OLD.submitted_at IS NOT NULL AND NEW.submitted_at IS DISTINCT FROM OLD.submitted_at) THEN
                    RAISE EXCEPTION 'Request ownership and first submission are immutable.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER intake_request_immutable_identity BEFORE UPDATE ON project_requests
                FOR EACH ROW EXECUTE FUNCTION intake_preserve_request_identity();
            CREATE OR REPLACE FUNCTION intake_preserve_draft_owner() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.request_id IS DISTINCT FROM OLD.request_id OR NEW.customer_id IS DISTINCT FROM OLD.customer_id THEN
                    RAISE EXCEPTION 'Draft ownership is immutable.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER intake_draft_immutable_owner BEFORE UPDATE ON request_drafts
                FOR EACH ROW EXECUTE FUNCTION intake_preserve_draft_owner();
            CREATE OR REPLACE FUNCTION intake_history_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Intake history is append-only.' USING ERRCODE='55000';
            END; $$;
            DO $$
            DECLARE target text;
            BEGIN
                FOREACH target IN ARRAY ARRAY['request_revisions','request_assignments','information_requests','information_responses','information_resolutions','request_state_changes','intake_notification_intents'] LOOP
                    EXECUTE format('CREATE TRIGGER intake_history_no_change BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION intake_history_append_only()',target);
                    EXECUTE format('CREATE TRIGGER intake_history_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION intake_history_append_only()',target);
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN
                        EXECUTE format('REVOKE UPDATE,DELETE,TRUNCATE ON %I FROM holoul_app',target);
                        EXECUTE format('GRANT SELECT,INSERT ON %I TO holoul_app',target);
                    END IF;
                END LOOP;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN
                    GRANT USAGE ON SEQUENCE request_reference_sequence TO holoul_app;
                END IF;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE project_requests DROP CONSTRAINT project_requests_information_fk;
            ALTER TABLE project_requests DROP CONSTRAINT project_requests_latest_revision_fk;
            DROP TABLE intake_notification_intents,intake_submission_keys,request_state_changes,information_resolutions,information_responses,information_requests,request_assignments,request_revisions,request_drafts,project_requests;
            DROP SEQUENCE IF EXISTS request_reference_sequence;
            DROP FUNCTION intake_history_append_only(),intake_preserve_draft_owner(),intake_preserve_request_identity();
            ALTER TABLE customers DROP CONSTRAINT customers_id_user_unique;
            SQL);
    }
};
