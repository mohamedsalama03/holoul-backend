<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE discovery_records (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                request_id uuid NOT NULL UNIQUE,
                customer_id uuid NOT NULL,
                latest_revision_number integer NOT NULL DEFAULT 0 CHECK (latest_revision_number>=0),
                current_revision_id uuid,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(id,request_id,customer_id),
                FOREIGN KEY(request_id,customer_id) REFERENCES project_requests(id,customer_id) ON DELETE RESTRICT
            );
            CREATE TABLE discovery_revisions (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                discovery_id uuid NOT NULL,
                request_id uuid NOT NULL,
                customer_id uuid NOT NULL,
                revision_number integer NOT NULL CHECK (revision_number>0),
                source_intake_revision_id uuid NOT NULL,
                state varchar(20) NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','in_progress','completed')),
                lock_version bigint NOT NULL DEFAULT 1 CHECK(lock_version>0),
                summary text NOT NULL CHECK(char_length(btrim(summary)) BETWEEN 1 AND 20000),
                internal_notes text NOT NULL DEFAULT '' CHECK(char_length(internal_notes)<=10000),
                author_id uuid NOT NULL,
                staff_kind varchar(16) NOT NULL DEFAULT 'staff' CHECK(staff_kind='staff'),
                completed_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CHECK ((state='completed')=(completed_at IS NOT NULL)),
                UNIQUE(discovery_id,revision_number), UNIQUE(id,request_id,customer_id), UNIQUE(discovery_id,id,revision_number), UNIQUE(id,lock_version),
                FOREIGN KEY(discovery_id,request_id,customer_id) REFERENCES discovery_records(id,request_id,customer_id) ON DELETE RESTRICT,
                FOREIGN KEY(request_id,source_intake_revision_id) REFERENCES request_revisions(request_id,id) ON DELETE RESTRICT,
                FOREIGN KEY(author_id,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT
            );
            ALTER TABLE discovery_records ADD CONSTRAINT discovery_current_revision_fk
                FOREIGN KEY(id,current_revision_id,latest_revision_number) REFERENCES discovery_revisions(discovery_id,id,revision_number) ON DELETE RESTRICT;
            CREATE TABLE discovery_requirements (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                revision_id uuid NOT NULL REFERENCES discovery_revisions(id) ON DELETE RESTRICT,
                position integer NOT NULL CHECK(position BETWEEN 1 AND 100),
                title varchar(200) NOT NULL CHECK(char_length(btrim(title))>0),
                description text NOT NULL CHECK(char_length(btrim(description)) BETWEEN 1 AND 10000),
                category varchar(32) NOT NULL CHECK(category IN ('functional','non_functional','constraint')),
                priority varchar(16) NOT NULL CHECK(priority IN ('must','should','could')),
                notes text NOT NULL DEFAULT '' CHECK(char_length(notes)<=5000),
                status varchar(16) NOT NULL CHECK(status IN ('proposed','confirmed','excluded')),
                UNIQUE(revision_id,position)
            );
            CREATE TABLE discovery_signoffs (
                id uuid PRIMARY KEY CHECK (substring(id::text,15,1)='7'),
                revision_id uuid NOT NULL UNIQUE,
                revision_version bigint NOT NULL CHECK(revision_version>0),
                completed_by uuid NOT NULL,
                staff_kind varchar(16) NOT NULL DEFAULT 'staff' CHECK(staff_kind='staff'),
                completed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY(revision_id,revision_version) REFERENCES discovery_revisions(id,lock_version) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY(completed_by,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT
            );
            CREATE OR REPLACE FUNCTION discovery_preserve_revision() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' OR OLD.state='completed' OR NEW.discovery_id<>OLD.discovery_id
                    OR NEW.request_id<>OLD.request_id OR NEW.customer_id<>OLD.customer_id
                    OR NEW.revision_number<>OLD.revision_number OR NEW.source_intake_revision_id<>OLD.source_intake_revision_id
                    OR NEW.author_id<>OLD.author_id OR NEW.created_at<>OLD.created_at THEN
                    RAISE EXCEPTION 'Discovery history is immutable.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER discovery_revision_guard BEFORE UPDATE OR DELETE ON discovery_revisions FOR EACH ROW EXECUTE FUNCTION discovery_preserve_revision();
            CREATE OR REPLACE FUNCTION discovery_guard_requirement() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent uuid; phase text;
            BEGIN
                parent := CASE WHEN TG_OP='DELETE' THEN OLD.revision_id ELSE NEW.revision_id END;
                IF TG_OP='UPDATE' AND NEW.revision_id<>OLD.revision_id THEN
                    RAISE EXCEPTION 'Requirement ownership is immutable.' USING ERRCODE='23514';
                END IF;
                SELECT state INTO phase FROM discovery_revisions WHERE id=parent FOR UPDATE;
                IF phase='completed' THEN RAISE EXCEPTION 'Completed requirements are immutable.' USING ERRCODE='23514'; END IF;
                RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
            END; $$;
            CREATE TRIGGER discovery_requirement_guard BEFORE INSERT OR UPDATE OR DELETE ON discovery_requirements FOR EACH ROW EXECUTE FUNCTION discovery_guard_requirement();
            CREATE OR REPLACE FUNCTION discovery_check_signoff() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS(SELECT 1 FROM discovery_revisions r WHERE r.id=NEW.id AND r.state='completed'
                    AND (NOT EXISTS(SELECT 1 FROM discovery_signoffs s WHERE s.revision_id=r.id AND s.revision_version=r.lock_version)
                    OR NOT EXISTS(SELECT 1 FROM discovery_requirements q WHERE q.revision_id=r.id AND q.status='confirmed')
                    OR EXISTS(SELECT 1 FROM discovery_requirements q WHERE q.revision_id=r.id AND q.status='proposed'))) THEN
                    RAISE EXCEPTION 'Discovery requires explicit signoff and resolved requirements.' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER discovery_signoff_required AFTER INSERT OR UPDATE ON discovery_revisions
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION discovery_check_signoff();
            CREATE TRIGGER discovery_signoffs_no_change BEFORE UPDATE OR DELETE ON discovery_signoffs FOR EACH ROW EXECUTE FUNCTION intake_history_append_only();
            DO $$ DECLARE target text; BEGIN
                FOREACH target IN ARRAY ARRAY['discovery_records','discovery_revisions','discovery_requirements','discovery_signoffs'] LOOP
                    EXECUTE format('CREATE TRIGGER discovery_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION intake_history_append_only()',target);
                    IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN EXECUTE format('REVOKE TRUNCATE ON %I FROM holoul_app',target); END IF;
                END LOOP;
                IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN REVOKE UPDATE,DELETE ON discovery_signoffs FROM holoul_app; END IF;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE discovery_records DROP CONSTRAINT discovery_current_revision_fk; DROP TABLE discovery_signoffs,discovery_requirements,discovery_revisions,discovery_records; DROP FUNCTION discovery_preserve_revision(),discovery_guard_requirement(),discovery_check_signoff();');
    }
};
