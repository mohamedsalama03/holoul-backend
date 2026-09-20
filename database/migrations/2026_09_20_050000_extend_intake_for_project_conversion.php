<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE project_requests DROP CONSTRAINT project_requests_state_check;
            ALTER TABLE project_requests ADD CONSTRAINT project_requests_state_check CHECK
                (state IN ('draft','submitted','under_review','information_required','discovery','proposal','approved','converted','rejected','withdrawn'));
            ALTER TABLE request_state_changes DROP CONSTRAINT request_state_changes_to_state_check;
            ALTER TABLE request_state_changes ADD CONSTRAINT request_state_changes_to_state_check CHECK
                (to_state IN ('submitted','under_review','information_required','discovery','proposal','approved','converted','rejected','withdrawn'));
            ALTER TABLE request_state_changes DROP CONSTRAINT commercial_history_context;
            ALTER TABLE request_state_changes ADD CONSTRAINT commercial_history_context CHECK
                ((from_state NOT IN ('proposal','approved') AND to_state NOT IN ('proposal','approved','converted'))
                    OR (entity_version IS NOT NULL AND correlation_id IS NOT NULL));
            ALTER TABLE proposal_decisions ADD CONSTRAINT proposal_decisions_baseline_identity
                UNIQUE(id,proposal_id,request_id,customer_id,proposal_version);
            CREATE OR REPLACE FUNCTION intake_preserve_converted_request() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.state='converted' AND NEW IS DISTINCT FROM OLD THEN
                    RAISE EXCEPTION 'Converted requests are immutable source records.' USING ERRCODE='23514';
                END IF;
                IF NEW.state='converted' AND OLD.state NOT IN ('approved','converted') THEN
                    RAISE EXCEPTION 'Only an approved request can be converted.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER intake_converted_terminal BEFORE UPDATE ON project_requests
                FOR EACH ROW EXECUTE FUNCTION intake_preserve_converted_request();
            CREATE OR REPLACE FUNCTION proposal_check_request_state() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent uuid; phase text; issued boolean; accepted boolean;
            BEGIN
                IF TG_TABLE_NAME='project_requests' THEN parent := NEW.id; ELSE parent := NEW.request_id; END IF;
                SELECT state INTO phase FROM project_requests WHERE id=parent;
                SELECT EXISTS(SELECT 1 FROM proposals WHERE request_id=parent AND state='issued'),
                    EXISTS(SELECT 1 FROM proposals WHERE request_id=parent AND state='accepted') INTO issued,accepted;
                IF (phase='proposal')<>issued OR (phase IN ('approved','converted'))<>accepted THEN
                    RAISE EXCEPTION 'Request and current proposal states must commit together.' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END; $$;
            CREATE OR REPLACE FUNCTION proposal_guard_decision() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE p proposals%ROWTYPE; phase text;
            BEGIN
                SELECT * INTO p FROM proposals WHERE id=NEW.proposal_id FOR UPDATE;
                SELECT state INTO phase FROM project_requests WHERE id=p.request_id;
                IF p.lock_version<>NEW.proposal_version OR p.current_approval_id IS NULL
                    OR (NEW.decision IN ('accepted','declined') AND (p.state<>'issued' OR p.valid_until<=clock_timestamp()))
                    OR (NEW.decision='rescinded' AND (p.state<>'accepted' OR phase<>'approved')) THEN
                    RAISE EXCEPTION 'Decision requires the current valid unconverted proposal.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER intake_converted_terminal ON project_requests;
            DROP FUNCTION intake_preserve_converted_request();
            ALTER TABLE proposal_decisions DROP CONSTRAINT proposal_decisions_baseline_identity;
            ALTER TABLE project_requests DROP CONSTRAINT project_requests_state_check;
            ALTER TABLE project_requests ADD CONSTRAINT project_requests_state_check CHECK
                (state IN ('draft','submitted','under_review','information_required','discovery','proposal','approved','rejected','withdrawn'));
            ALTER TABLE request_state_changes DROP CONSTRAINT request_state_changes_to_state_check;
            ALTER TABLE request_state_changes ADD CONSTRAINT request_state_changes_to_state_check CHECK
                (to_state IN ('submitted','under_review','information_required','discovery','proposal','approved','rejected','withdrawn'));
            ALTER TABLE request_state_changes DROP CONSTRAINT commercial_history_context;
            ALTER TABLE request_state_changes ADD CONSTRAINT commercial_history_context CHECK
                ((from_state NOT IN ('proposal','approved') AND to_state NOT IN ('proposal','approved'))
                    OR (entity_version IS NOT NULL AND correlation_id IS NOT NULL));
            CREATE OR REPLACE FUNCTION proposal_check_request_state() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent uuid; phase text; issued boolean; accepted boolean;
            BEGIN
                IF TG_TABLE_NAME='project_requests' THEN parent := NEW.id; ELSE parent := NEW.request_id; END IF;
                SELECT state INTO phase FROM project_requests WHERE id=parent;
                SELECT EXISTS(SELECT 1 FROM proposals WHERE request_id=parent AND state='issued'),
                    EXISTS(SELECT 1 FROM proposals WHERE request_id=parent AND state='accepted') INTO issued,accepted;
                IF (phase='proposal')<>issued OR (phase='approved')<>accepted THEN
                    RAISE EXCEPTION 'Request and current proposal states must commit together.' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END; $$;
            CREATE OR REPLACE FUNCTION proposal_guard_decision() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE p proposals%ROWTYPE;
            BEGIN
                SELECT * INTO p FROM proposals WHERE id=NEW.proposal_id FOR UPDATE;
                IF p.lock_version<>NEW.proposal_version OR p.current_approval_id IS NULL
                    OR (NEW.decision IN ('accepted','declined') AND (p.state<>'issued' OR p.valid_until<=clock_timestamp()))
                    OR (NEW.decision='rescinded' AND p.state<>'accepted') THEN
                    RAISE EXCEPTION 'Decision requires the current valid proposal.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            SQL);
    }
};
