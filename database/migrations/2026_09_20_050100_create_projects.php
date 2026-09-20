<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE SEQUENCE IF NOT EXISTS project_reference_sequence AS bigint MINVALUE 1 NO CYCLE;
            CREATE TABLE projects (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                source_request_id uuid NOT NULL UNIQUE,
                accepted_proposal_id uuid NOT NULL UNIQUE,
                accepted_decision_id uuid NOT NULL UNIQUE,
                accepted_proposal_version bigint NOT NULL CHECK(accepted_proposal_version>0),
                customer_id uuid NOT NULL,
                customer_user_id uuid NOT NULL,
                reference varchar(40) NOT NULL UNIQUE CHECK(reference ~ '^PRJ-[0-9]{4}-[0-9]{5,19}$'),
                name varchar(200) NOT NULL CHECK(char_length(btrim(name))>0),
                state varchar(20) NOT NULL DEFAULT 'planning' CHECK(state IN ('planning','design','development','testing','deployment','on_hold','completed','cancelled')),
                previous_phase varchar(20) CHECK(previous_phase IN ('planning','design','development','testing','deployment')),
                phase_epoch bigint NOT NULL DEFAULT 1 CHECK(phase_epoch>0),
                lock_version bigint NOT NULL DEFAULT 1 CHECK(lock_version>0),
                created_by uuid NOT NULL,
                staff_kind varchar(16) NOT NULL DEFAULT 'staff' CHECK(staff_kind='staff'),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CHECK((state='on_hold')=(previous_phase IS NOT NULL)),
                UNIQUE(id,customer_id), UNIQUE(id,customer_user_id),
                FOREIGN KEY(source_request_id,customer_id) REFERENCES project_requests(id,customer_id) ON DELETE RESTRICT,
                FOREIGN KEY(source_request_id,customer_user_id) REFERENCES project_requests(id,customer_user_id) ON DELETE RESTRICT,
                FOREIGN KEY(accepted_proposal_id,source_request_id,customer_id) REFERENCES proposals(id,request_id,customer_id) ON DELETE RESTRICT,
                FOREIGN KEY(accepted_decision_id,accepted_proposal_id,source_request_id,customer_id,accepted_proposal_version)
                    REFERENCES proposal_decisions(id,proposal_id,request_id,customer_id,proposal_version) ON DELETE RESTRICT,
                FOREIGN KEY(created_by,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT
            );
            ALTER SEQUENCE project_reference_sequence OWNED BY projects.reference;
            CREATE INDEX projects_owner_list ON projects(customer_id,created_at,id);
            CREATE INDEX projects_state_list ON projects(state,created_at,id);
            CREATE TABLE project_members (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
                user_id uuid NOT NULL,
                staff_kind varchar(16) NOT NULL DEFAULT 'staff' CHECK(staff_kind='staff'),
                role varchar(24) NOT NULL CHECK(role IN ('project_manager','business_analyst','contributor')),
                active boolean NOT NULL DEFAULT true,
                added_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                added_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                removed_by uuid REFERENCES users(id) ON DELETE RESTRICT,
                removed_at timestamptz,
                CHECK(active=(removed_at IS NULL)), CHECK(active=(removed_by IS NULL)),
                UNIQUE(id,project_id),
                FOREIGN KEY(user_id,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT
            );
            CREATE UNIQUE INDEX project_members_one_active ON project_members(project_id,user_id) WHERE active;
            CREATE INDEX project_members_user_scope ON project_members(user_id,project_id) WHERE active;
            CREATE TABLE project_membership_history (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
                member_id uuid NOT NULL,
                event varchar(12) NOT NULL CHECK(event IN ('added','removed')),
                actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                entity_version bigint NOT NULL CHECK(entity_version>0),
                correlation_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(member_id,event),
                FOREIGN KEY(member_id,project_id) REFERENCES project_members(id,project_id) ON DELETE RESTRICT
            );
            CREATE TABLE project_phase_evidence (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
                phase_epoch bigint NOT NULL CHECK(phase_epoch>0),
                phase varchar(20) NOT NULL,
                kind varchar(32) NOT NULL,
                summary text NOT NULL CHECK(char_length(btrim(summary)) BETWEEN 1 AND 5000),
                recorded_by uuid NOT NULL,
                staff_kind varchar(16) NOT NULL DEFAULT 'staff' CHECK(staff_kind='staff'),
                entity_version bigint NOT NULL CHECK(entity_version>0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CHECK((phase,kind) IN (('planning','plan_approved'),('design','design_approved'),('development','delivery_candidate'),('testing','qa_passed'),('deployment','deployment_succeeded'))),
                UNIQUE(project_id,phase_epoch,kind,entity_version), UNIQUE(id,project_id,phase_epoch),
                FOREIGN KEY(recorded_by,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT
            );
            CREATE TABLE project_completion_confirmations (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                project_id uuid NOT NULL,
                phase_epoch bigint NOT NULL CHECK(phase_epoch>0),
                deployment_evidence_id uuid NOT NULL,
                confirmed_by uuid NOT NULL,
                entity_version bigint NOT NULL CHECK(entity_version>0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(project_id,deployment_evidence_id),
                FOREIGN KEY(project_id,confirmed_by) REFERENCES projects(id,customer_user_id) ON DELETE RESTRICT,
                FOREIGN KEY(deployment_evidence_id,project_id,phase_epoch) REFERENCES project_phase_evidence(id,project_id,phase_epoch) ON DELETE RESTRICT
            );
            CREATE TABLE project_state_changes (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
                from_state varchar(20), to_state varchar(20) NOT NULL,
                from_epoch bigint, to_epoch bigint NOT NULL CHECK(to_epoch>0),
                actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                entity_version bigint NOT NULL CHECK(entity_version>0),
                reason text CHECK(reason IS NULL OR char_length(btrim(reason)) BETWEEN 1 AND 5000),
                customer_communication text CHECK(customer_communication IS NULL OR char_length(btrim(customer_communication)) BETWEEN 1 AND 5000),
                resume_conditions text CHECK(resume_conditions IS NULL OR char_length(btrim(resume_conditions)) BETWEEN 1 AND 5000),
                correlation_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(project_id,entity_version), UNIQUE(project_id,to_epoch),
                CHECK((from_state IS NULL AND to_state='planning' AND from_epoch IS NULL AND to_epoch=1 AND entity_version=1)
                    OR (from_epoch IS NOT NULL AND to_epoch=from_epoch+1 AND
                    ((from_state,to_state) IN (('planning','design'),('design','development'),('development','testing'),('testing','deployment'),('testing','development'),('deployment','testing'),('deployment','completed'))
                    OR (from_state IN ('planning','design','development','testing','deployment') AND to_state='on_hold')
                    OR (from_state='on_hold' AND to_state IN ('planning','design','development','testing','deployment'))
                    OR (from_state IN ('planning','design','development','testing','deployment','on_hold') AND to_state='cancelled')))),
                CHECK(to_state NOT IN ('on_hold','cancelled') OR (reason IS NOT NULL AND customer_communication IS NOT NULL)),
                CHECK(from_state<>'on_hold' OR to_state='cancelled' OR (reason IS NOT NULL AND resume_conditions IS NOT NULL)),
                CHECK((from_state,to_state) NOT IN (('testing','development'),('deployment','testing')) OR (reason IS NOT NULL AND customer_communication IS NOT NULL))
            );
            CREATE INDEX project_state_history ON project_state_changes(project_id,created_at,id);
            CREATE TABLE milestones (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
                name varchar(200) NOT NULL CHECK(char_length(btrim(name))>0),
                description text NOT NULL DEFAULT '' CHECK(char_length(description)<=5000),
                state varchar(20) NOT NULL DEFAULT 'upcoming' CHECK(state IN ('upcoming','in_progress','completed','delayed')),
                due_date date,
                display_order integer NOT NULL CHECK(display_order BETWEEN 1 AND 1000),
                responsible_member_id uuid,
                customer_visible boolean NOT NULL DEFAULT false,
                lock_version bigint NOT NULL DEFAULT 1 CHECK(lock_version>0),
                created_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(id,project_id), UNIQUE(project_id,display_order),
                FOREIGN KEY(responsible_member_id,project_id) REFERENCES project_members(id,project_id) ON DELETE RESTRICT
            );
            CREATE TABLE milestone_changes (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                milestone_id uuid NOT NULL,
                project_id uuid NOT NULL,
                event varchar(16) NOT NULL CHECK(event IN ('created','updated','started','delayed','completed')),
                from_state varchar(20), to_state varchar(20) NOT NULL CHECK(to_state IN ('upcoming','in_progress','completed','delayed')),
                actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                milestone_version bigint NOT NULL CHECK(milestone_version>0),
                entity_version bigint NOT NULL CHECK(entity_version>0),
                reason text CHECK(reason IS NULL OR char_length(btrim(reason)) BETWEEN 1 AND 5000),
                correlation_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(milestone_id,milestone_version),
                FOREIGN KEY(milestone_id,project_id) REFERENCES milestones(id,project_id) ON DELETE RESTRICT
            );
            CREATE TABLE project_updates (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
                author_id uuid NOT NULL,
                staff_kind varchar(16) NOT NULL DEFAULT 'staff' CHECK(staff_kind='staff'),
                content text NOT NULL CHECK(char_length(btrim(content)) BETWEEN 1 AND 10000),
                entity_version bigint NOT NULL CHECK(entity_version>0),
                published_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY(author_id,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT
            );
            CREATE INDEX project_updates_feed ON project_updates(project_id,published_at,id);
            CREATE TABLE project_activity (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
                event varchar(64) NOT NULL CHECK(event ~ '^[a-z][a-z_]{1,63}$'),
                actor_id uuid REFERENCES users(id) ON DELETE RESTRICT,
                entity_version bigint NOT NULL CHECK(entity_version>0),
                reason text CHECK(reason IS NULL OR char_length(btrim(reason)) BETWEEN 1 AND 5000),
                correlation_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp()
            );
            CREATE INDEX project_activity_history ON project_activity(project_id,created_at,id);
            CREATE OR REPLACE FUNCTION project_guard_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Projects are retained.' USING ERRCODE='23514'; END IF;
                IF TG_OP='INSERT' THEN
                    IF NEW.state<>'planning' OR NEW.phase_epoch<>1 OR NEW.lock_version<>1 OR NOT EXISTS(
                        SELECT 1 FROM project_requests r JOIN proposals p ON p.request_id=r.id
                        JOIN proposal_decisions d ON d.id=NEW.accepted_decision_id AND d.proposal_id=p.id
                        WHERE r.id=NEW.source_request_id AND r.state='approved' AND p.id=NEW.accepted_proposal_id
                          AND p.state='accepted' AND p.current_approval_id IS NOT NULL AND d.decision='accepted'
                          AND d.proposal_version=NEW.accepted_proposal_version AND p.lock_version=d.proposal_version+1
                          AND d.decided_by=NEW.customer_user_id AND d.decided_at<p.valid_until
                          AND NOT EXISTS(SELECT 1 FROM proposal_decisions revoked WHERE revoked.proposal_id=p.id AND revoked.decision='rescinded')) THEN
                        RAISE EXCEPTION 'Conversion requires approved request and exact accepted baseline.' USING ERRCODE='23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF (to_jsonb(NEW)-ARRAY['state','previous_phase','phase_epoch','lock_version','updated_at'])
                    IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','previous_phase','phase_epoch','lock_version','updated_at']) THEN
                    RAISE EXCEPTION 'Project identity and baseline are immutable.' USING ERRCODE='23514';
                END IF;
                IF OLD.state IN ('completed','cancelled') OR NEW.lock_version<>OLD.lock_version+1 THEN
                    RAISE EXCEPTION 'Terminal project or stale version.' USING ERRCODE='23514';
                END IF;
                IF NEW.state=OLD.state AND (NEW.phase_epoch<>OLD.phase_epoch OR NEW.previous_phase IS DISTINCT FROM OLD.previous_phase) THEN
                    RAISE EXCEPTION 'Phase evidence epoch changes only on transition.' USING ERRCODE='23514';
                END IF;
                IF NEW.state<>OLD.state THEN
                    IF NEW.phase_epoch<>OLD.phase_epoch+1 OR NOT (
                        (OLD.state,NEW.state) IN (('planning','design'),('design','development'),('development','testing'),('testing','deployment'),('testing','development'),('deployment','testing'),('deployment','completed'))
                        OR (OLD.state IN ('planning','design','development','testing','deployment') AND NEW.state='on_hold' AND NEW.previous_phase=OLD.state)
                        OR (OLD.state='on_hold' AND NEW.state=OLD.previous_phase)
                        OR NEW.state='cancelled') THEN
                        RAISE EXCEPTION 'Invalid explicit project transition.' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER projects_identity_guard BEFORE INSERT OR UPDATE OR DELETE ON projects FOR EACH ROW EXECUTE FUNCTION project_guard_identity();
            CREATE OR REPLACE FUNCTION project_check_conversion_pair() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE request uuid; phase text; linked boolean;
            BEGIN
                IF TG_TABLE_NAME='projects' THEN request:=NEW.source_request_id; ELSE request:=NEW.id; END IF;
                SELECT state INTO phase FROM project_requests WHERE id=request;
                SELECT EXISTS(SELECT 1 FROM projects WHERE source_request_id=request) INTO linked;
                IF (phase='converted')<>linked THEN
                    RAISE EXCEPTION 'Project creation and source conversion must commit together.' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER project_conversion_pair_required AFTER INSERT ON projects
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION project_check_conversion_pair();
            CREATE CONSTRAINT TRIGGER request_conversion_pair_required AFTER INSERT OR UPDATE ON project_requests
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION project_check_conversion_pair();
            CREATE OR REPLACE FUNCTION project_check_state_history() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE history project_state_changes%ROWTYPE; evidence project_phase_evidence%ROWTYPE; expected text;
            BEGIN
                IF TG_OP='UPDATE' AND NEW.state=OLD.state THEN RETURN NULL; END IF;
                SELECT * INTO history FROM project_state_changes WHERE project_id=NEW.id AND entity_version=NEW.lock_version;
                IF NOT FOUND OR history.to_state<>NEW.state OR history.to_epoch<>NEW.phase_epoch THEN
                    RAISE EXCEPTION 'Project transition requires matching history.' USING ERRCODE='23514';
                END IF;
                IF TG_OP='INSERT' THEN
                    IF history.from_state IS NOT NULL THEN RAISE EXCEPTION 'Invalid project creation history.' USING ERRCODE='23514'; END IF;
                    RETURN NULL;
                END IF;
                IF history.from_state<>OLD.state OR history.from_epoch<>OLD.phase_epoch OR NOT EXISTS(
                    SELECT 1 FROM project_members m JOIN users u ON u.id=m.user_id
                    WHERE m.project_id=NEW.id AND m.user_id=history.actor_id AND m.role='project_manager' AND m.active AND u.enabled) THEN
                    RAISE EXCEPTION 'Transition requires current assigned project manager.' USING ERRCODE='23514';
                END IF;
                expected := CASE WHEN (OLD.state,NEW.state)=('planning','design') THEN 'plan_approved'
                    WHEN (OLD.state,NEW.state)=('design','development') THEN 'design_approved'
                    WHEN (OLD.state,NEW.state)=('development','testing') THEN 'delivery_candidate'
                    WHEN (OLD.state,NEW.state)=('testing','deployment') THEN 'qa_passed'
                    WHEN (OLD.state,NEW.state)=('deployment','completed') THEN 'deployment_succeeded' ELSE NULL END;
                IF expected IS NOT NULL THEN
                    SELECT * INTO evidence FROM project_phase_evidence WHERE project_id=NEW.id AND phase_epoch=OLD.phase_epoch AND kind=expected ORDER BY entity_version DESC LIMIT 1;
                    IF NOT FOUND THEN RAISE EXCEPTION 'Current phase evidence required.' USING ERRCODE='23514'; END IF;
                    IF expected='plan_approved' AND EXISTS(SELECT 1 FROM project_membership_history WHERE project_id=NEW.id AND entity_version>evidence.entity_version) THEN
                        RAISE EXCEPTION 'Team changes invalidate recorded plan approval.' USING ERRCODE='23514';
                    END IF;
                    IF expected='deployment_succeeded' AND NOT EXISTS(SELECT 1 FROM project_completion_confirmations
                        WHERE project_id=NEW.id AND phase_epoch=OLD.phase_epoch AND deployment_evidence_id=evidence.id) THEN
                        RAISE EXCEPTION 'Current owner completion confirmation required.' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER projects_state_history_required AFTER INSERT OR UPDATE ON projects
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION project_check_state_history();
            CREATE OR REPLACE FUNCTION project_guard_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE p projects%ROWTYPE;
            BEGIN
                SELECT * INTO p FROM projects WHERE id=NEW.project_id FOR UPDATE;
                IF NEW.phase_epoch<>p.phase_epoch OR NEW.entity_version<>p.lock_version THEN
                    RAISE EXCEPTION 'Evidence requires current project epoch and version.' USING ERRCODE='23514';
                END IF;
                IF TG_TABLE_NAME='project_phase_evidence' THEN
                    IF NEW.phase<>p.state OR NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.recorded_by AND kind='staff' AND enabled)
                        OR (NEW.phase='planning' AND NOT EXISTS(SELECT 1 FROM project_members WHERE project_id=p.id AND user_id=NEW.recorded_by AND role='project_manager' AND active)) THEN
                        RAISE EXCEPTION 'Evidence requires current phase and assigned staff.' USING ERRCODE='23514';
                    END IF;
                ELSE
                    IF p.state<>'deployment' OR NEW.deployment_evidence_id IS DISTINCT FROM
                        (SELECT id FROM project_phase_evidence WHERE project_id=p.id AND phase_epoch=p.phase_epoch AND kind='deployment_succeeded' ORDER BY entity_version DESC LIMIT 1) THEN
                        RAISE EXCEPTION 'Owner confirmation requires successful current deployment.' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER project_evidence_guard BEFORE INSERT ON project_phase_evidence FOR EACH ROW EXECUTE FUNCTION project_guard_evidence();
            CREATE TRIGGER project_confirmation_guard BEFORE INSERT ON project_completion_confirmations FOR EACH ROW EXECUTE FUNCTION project_guard_evidence();
            CREATE OR REPLACE FUNCTION project_guard_member() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE phase text;
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Membership history retained.' USING ERRCODE='23514'; END IF;
                SELECT state INTO phase FROM projects WHERE id=NEW.project_id FOR UPDATE;
                IF phase IN ('completed','cancelled') THEN RAISE EXCEPTION 'Terminal team is immutable.' USING ERRCODE='23514'; END IF;
                IF TG_OP='INSERT' AND (NOT NEW.active OR NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.user_id AND kind='staff' AND enabled)) THEN
                    RAISE EXCEPTION 'Membership requires active staff.' USING ERRCODE='23514';
                END IF;
                IF TG_OP='UPDATE' AND ((to_jsonb(NEW)-ARRAY['active','removed_by','removed_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['active','removed_by','removed_at']) OR NOT OLD.active OR NEW.active) THEN
                    RAISE EXCEPTION 'Membership can only be explicitly removed.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER project_member_guard BEFORE INSERT OR UPDATE OR DELETE ON project_members FOR EACH ROW EXECUTE FUNCTION project_guard_member();
            CREATE OR REPLACE FUNCTION project_check_member_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS(SELECT 1 FROM project_membership_history WHERE member_id=NEW.id AND event=CASE WHEN NEW.active THEN 'added' ELSE 'removed' END)
                    OR NOT EXISTS(SELECT 1 FROM project_members WHERE project_id=NEW.project_id AND active AND role='project_manager')
                    OR (NOT NEW.active AND EXISTS(SELECT 1 FROM milestones WHERE responsible_member_id=NEW.id AND state<>'completed')) THEN
                    RAISE EXCEPTION 'Membership requires history, a manager and reassigned open milestones.' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER project_member_history_required AFTER INSERT OR UPDATE ON project_members DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION project_check_member_history();
            CREATE OR REPLACE FUNCTION project_guard_milestone() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE phase text;
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Milestones are retained.' USING ERRCODE='23514'; END IF;
                SELECT state INTO phase FROM projects WHERE id=NEW.project_id FOR UPDATE;
                IF phase IN ('completed','cancelled') OR (NEW.responsible_member_id IS NOT NULL AND NOT EXISTS(
                    SELECT 1 FROM project_members m JOIN users u ON u.id=m.user_id WHERE m.id=NEW.responsible_member_id AND m.project_id=NEW.project_id AND m.active AND u.enabled)) THEN
                    RAISE EXCEPTION 'Milestone requires active parent and assigned staff.' USING ERRCODE='23514';
                END IF;
                IF TG_OP='INSERT' AND (NEW.state<>'upcoming' OR NEW.lock_version<>1) THEN RAISE EXCEPTION 'Milestones start upcoming.' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' THEN
                    IF (NEW.id,NEW.project_id,NEW.created_by,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.project_id,OLD.created_by,OLD.created_at)
                        OR NEW.lock_version<>OLD.lock_version+1 OR OLD.state='completed' THEN RAISE EXCEPTION 'Milestone identity/version is immutable.' USING ERRCODE='23514'; END IF;
                    IF NEW.state<>OLD.state AND NOT ((OLD.state IN ('upcoming','delayed') AND NEW.state='in_progress')
                        OR (OLD.state IN ('upcoming','in_progress') AND NEW.state='delayed') OR (OLD.state='in_progress' AND NEW.state='completed')) THEN
                        RAISE EXCEPTION 'Invalid milestone transition.' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER project_milestone_guard BEFORE INSERT OR UPDATE OR DELETE ON milestones FOR EACH ROW EXECUTE FUNCTION project_guard_milestone();
            CREATE OR REPLACE FUNCTION project_check_milestone_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS(SELECT 1 FROM milestone_changes WHERE milestone_id=NEW.id AND milestone_version=NEW.lock_version AND to_state=NEW.state) THEN
                    RAISE EXCEPTION 'Milestone mutation requires history.' USING ERRCODE='23514'; END IF;
                RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER milestone_history_required AFTER INSERT OR UPDATE ON milestones DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION project_check_milestone_history();
            CREATE OR REPLACE FUNCTION project_guard_update() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE phase text;
            BEGIN
                SELECT state INTO phase FROM projects WHERE id=NEW.project_id FOR UPDATE;
                IF phase IN ('completed','cancelled') OR NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.author_id AND kind='staff' AND enabled) THEN
                    RAISE EXCEPTION 'Updates require assigned staff and active project.' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER project_update_guard BEFORE INSERT ON project_updates FOR EACH ROW EXECUTE FUNCTION project_guard_update();
            CREATE OR REPLACE FUNCTION project_guard_history_insert() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE p projects%ROWTYPE; m project_members%ROWTYPE; item milestones%ROWTYPE;
            BEGIN
                SELECT * INTO p FROM projects WHERE id=NEW.project_id FOR UPDATE;
                IF NEW.entity_version<>p.lock_version THEN RAISE EXCEPTION 'History must match current project version.' USING ERRCODE='23514'; END IF;
                IF TG_TABLE_NAME='project_state_changes' THEN
                    IF NEW.to_state<>p.state OR NEW.to_epoch<>p.phase_epoch
                        OR (NEW.to_epoch=1 AND NEW.actor_id<>p.created_by)
                        OR (NEW.to_epoch>1 AND NOT EXISTS(SELECT 1 FROM project_state_changes WHERE project_id=p.id AND to_epoch=NEW.from_epoch AND to_state=NEW.from_state)) THEN
                        RAISE EXCEPTION 'State history must describe actual continuous transition.' USING ERRCODE='23514';
                    END IF;
                ELSIF TG_TABLE_NAME='project_membership_history' THEN
                    SELECT * INTO m FROM project_members WHERE id=NEW.member_id;
                    IF m.project_id<>p.id OR m.active<>(NEW.event='added')
                        OR NEW.actor_id<>(CASE WHEN NEW.event='added' THEN m.added_by ELSE m.removed_by END) THEN
                        RAISE EXCEPTION 'Membership history must describe actual membership.' USING ERRCODE='23514';
                    END IF;
                ELSE
                    SELECT * INTO item FROM milestones WHERE id=NEW.milestone_id;
                    IF item.project_id<>p.id OR item.lock_version<>NEW.milestone_version OR item.state<>NEW.to_state
                        OR (NEW.milestone_version=1 AND (NEW.event<>'created' OR NEW.from_state IS NOT NULL))
                        OR (NEW.milestone_version>1 AND NOT EXISTS(SELECT 1 FROM milestone_changes WHERE milestone_id=item.id AND milestone_version=NEW.milestone_version-1 AND to_state=NEW.from_state)) THEN
                        RAISE EXCEPTION 'Milestone history must describe actual continuous mutation.' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER project_state_history_insert_guard BEFORE INSERT ON project_state_changes FOR EACH ROW EXECUTE FUNCTION project_guard_history_insert();
            CREATE TRIGGER project_member_history_insert_guard BEFORE INSERT ON project_membership_history FOR EACH ROW EXECUTE FUNCTION project_guard_history_insert();
            CREATE TRIGGER project_milestone_history_insert_guard BEFORE INSERT ON milestone_changes FOR EACH ROW EXECUTE FUNCTION project_guard_history_insert();
            DO $$ DECLARE target text; BEGIN
                FOREACH target IN ARRAY ARRAY['project_membership_history','project_phase_evidence','project_completion_confirmations','project_state_changes','milestone_changes','project_updates','project_activity'] LOOP
                    EXECUTE format('CREATE TRIGGER project_history_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION intake_history_append_only()',target);
                    IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN EXECUTE format('REVOKE UPDATE,DELETE ON %I FROM holoul_app',target); END IF;
                END LOOP;
                FOREACH target IN ARRAY ARRAY['projects','project_members','project_membership_history','project_phase_evidence','project_completion_confirmations','project_state_changes','milestones','milestone_changes','project_updates','project_activity'] LOOP
                    EXECUTE format('CREATE TRIGGER projects_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION intake_history_append_only()',target);
                    IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN EXECUTE format('REVOKE TRUNCATE ON %I FROM holoul_app',target); END IF;
                END LOOP;
                IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN GRANT USAGE ON SEQUENCE project_reference_sequence TO holoul_app; END IF;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN IF EXISTS(SELECT 1 FROM projects) THEN RAISE EXCEPTION 'Retained projects require a forward migration.'; END IF; END; $$;
            DROP TRIGGER request_conversion_pair_required ON project_requests;
            DROP TABLE project_activity,project_updates,milestone_changes,milestones,project_state_changes,project_completion_confirmations,project_phase_evidence,project_membership_history,project_members,projects;
            DROP FUNCTION project_guard_identity(),project_check_conversion_pair(),project_check_state_history(),project_guard_evidence(),project_guard_member(),project_check_member_history(),project_guard_milestone(),project_check_milestone_history(),project_guard_update(),project_guard_history_insert();
            SQL);
    }
};
