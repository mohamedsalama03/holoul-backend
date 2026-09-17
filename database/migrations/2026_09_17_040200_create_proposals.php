<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE SEQUENCE IF NOT EXISTS proposal_reference_sequence AS bigint MINVALUE 1 NO CYCLE;
            CREATE TABLE proposal_series (
                request_id uuid PRIMARY KEY REFERENCES project_requests(id) ON DELETE RESTRICT,
                latest_revision_number integer NOT NULL DEFAULT 0 CHECK(latest_revision_number>=0)
            );
            CREATE TABLE proposals (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                request_id uuid NOT NULL,
                customer_id uuid NOT NULL,
                discovery_revision_id uuid NOT NULL,
                revision_number integer NOT NULL CHECK(revision_number>0),
                state varchar(24) NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','internally_approved','issued','accepted','declined','expired','superseded','withdrawn','rescinded')),
                number varchar(40) UNIQUE CHECK(number ~ '^PROP-[0-9]{4}-[0-9]{5,19}$'),
                lock_version bigint NOT NULL DEFAULT 1 CHECK(lock_version>0),
                content_version bigint NOT NULL DEFAULT 1 CHECK(content_version>0),
                scope_summary text NOT NULL CHECK(char_length(btrim(scope_summary)) BETWEEN 1 AND 20000),
                timeline text NOT NULL CHECK(char_length(btrim(timeline)) BETWEEN 1 AND 5000),
                commercial_notes text NOT NULL DEFAULT '' CHECK(char_length(commercial_notes)<=10000),
                pricing_mode varchar(8) NOT NULL CHECK(pricing_mode IN ('fixed','items')),
                amount_minor bigint NOT NULL CHECK(amount_minor>=0),
                currency char(3) NOT NULL REFERENCES currencies(code) ON DELETE RESTRICT,
                valid_until timestamptz NOT NULL,
                author_id uuid NOT NULL,
                staff_kind varchar(16) NOT NULL DEFAULT 'staff' CHECK(staff_kind='staff'),
                current_approval_id uuid,
                issued_at timestamptz,
                issued_by uuid,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(request_id,revision_number), UNIQUE(id,request_id,customer_id), UNIQUE(id,content_version),
                CHECK((number IS NULL)=(issued_at IS NULL)), CHECK((issued_by IS NULL)=(issued_at IS NULL)),
                CHECK((state IN ('draft','internally_approved'))=(issued_at IS NULL)),
                CHECK((state='draft')=(current_approval_id IS NULL)),
                CHECK(issued_at IS NULL OR valid_until>issued_at),
                FOREIGN KEY(request_id,customer_id) REFERENCES project_requests(id,customer_id) ON DELETE RESTRICT,
                FOREIGN KEY(discovery_revision_id,request_id,customer_id) REFERENCES discovery_revisions(id,request_id,customer_id) ON DELETE RESTRICT,
                FOREIGN KEY(author_id,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT,
                FOREIGN KEY(issued_by,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT
            );
            ALTER SEQUENCE proposal_reference_sequence OWNED BY proposals.number;
            CREATE UNIQUE INDEX proposals_one_current_issue ON proposals(request_id) WHERE state='issued';
            CREATE UNIQUE INDEX proposals_one_acceptance ON proposals(request_id) WHERE state='accepted';
            CREATE INDEX proposals_request_history ON proposals(request_id,revision_number DESC);
            CREATE INDEX proposals_expiry ON proposals(valid_until,id) WHERE state='issued';
            CREATE TABLE proposal_items (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                proposal_id uuid NOT NULL REFERENCES proposals(id) ON DELETE RESTRICT,
                position integer NOT NULL CHECK(position BETWEEN 1 AND 100),
                title varchar(200) NOT NULL CHECK(char_length(btrim(title))>0),
                description text NOT NULL DEFAULT '' CHECK(char_length(description)<=5000),
                quantity integer NOT NULL CHECK(quantity BETWEEN 1 AND 1000000),
                unit_price_minor bigint NOT NULL CHECK(unit_price_minor>=0),
                line_total_minor bigint NOT NULL CHECK(line_total_minor>=0),
                CHECK(quantity::numeric*unit_price_minor::numeric=line_total_minor::numeric),
                UNIQUE(proposal_id,position)
            );
            CREATE TABLE proposal_deliverables (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                proposal_id uuid NOT NULL REFERENCES proposals(id) ON DELETE RESTRICT,
                position integer NOT NULL CHECK(position BETWEEN 1 AND 50),
                description text NOT NULL CHECK(char_length(btrim(description)) BETWEEN 1 AND 5000),
                UNIQUE(proposal_id,position)
            );
            CREATE TABLE proposal_contributors (
                proposal_id uuid NOT NULL REFERENCES proposals(id) ON DELETE RESTRICT,
                user_id uuid NOT NULL,
                staff_kind varchar(16) NOT NULL DEFAULT 'staff' CHECK(staff_kind='staff'),
                PRIMARY KEY(proposal_id,user_id),
                FOREIGN KEY(user_id,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT
            );
            CREATE TABLE proposal_approvals (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                proposal_id uuid NOT NULL REFERENCES proposals(id) ON DELETE RESTRICT,
                content_version bigint NOT NULL CHECK(content_version>0),
                approved_by uuid NOT NULL,
                staff_kind varchar(16) NOT NULL DEFAULT 'staff' CHECK(staff_kind='staff'),
                approved_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(proposal_id,content_version), UNIQUE(proposal_id,id,content_version),
                FOREIGN KEY(approved_by,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT
            );
            ALTER TABLE proposals ADD CONSTRAINT proposals_current_approval_fk FOREIGN KEY(id,current_approval_id,content_version)
                REFERENCES proposal_approvals(proposal_id,id,content_version) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED;
            CREATE TABLE proposal_decisions (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                proposal_id uuid NOT NULL,
                request_id uuid NOT NULL,
                customer_id uuid NOT NULL,
                decided_by uuid NOT NULL,
                decision varchar(16) NOT NULL CHECK(decision IN ('accepted','declined','rescinded')),
                proposal_version bigint NOT NULL CHECK(proposal_version>0),
                reason text CHECK(reason IS NULL OR char_length(btrim(reason)) BETWEEN 1 AND 5000),
                decided_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY(proposal_id,request_id,customer_id) REFERENCES proposals(id,request_id,customer_id) ON DELETE RESTRICT,
                FOREIGN KEY(request_id,decided_by) REFERENCES project_requests(id,customer_user_id) ON DELETE RESTRICT,
                UNIQUE(proposal_id,decision)
            );
            CREATE UNIQUE INDEX proposals_single_customer_decision ON proposal_decisions(proposal_id) WHERE decision IN ('accepted','declined');
            CREATE TABLE proposal_events (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                proposal_id uuid NOT NULL REFERENCES proposals(id) ON DELETE RESTRICT,
                event varchar(32) NOT NULL CHECK(event IN ('created','updated','approved','approval_invalidated','issued','accepted','declined','expired','superseded','withdrawn','rescinded','document_attached','document_removed')),
                actor_id uuid REFERENCES users(id) ON DELETE RESTRICT,
                version bigint NOT NULL CHECK(version>0),
                reason text CHECK(reason IS NULL OR char_length(btrim(reason)) BETWEEN 1 AND 5000),
                correlation_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp()
            );
            CREATE TABLE proposal_command_keys (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'),
                actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                key_hash char(64) NOT NULL CHECK(key_hash ~ '^[a-f0-9]{64}$'),
                input_hash char(64) NOT NULL CHECK(input_hash ~ '^[a-f0-9]{64}$'),
                request_id uuid NOT NULL REFERENCES project_requests(id) ON DELETE RESTRICT,
                operation varchar(32) NOT NULL,
                proposal_id uuid NOT NULL REFERENCES proposals(id) ON DELETE RESTRICT,
                result_state varchar(24) NOT NULL,
                result_version bigint NOT NULL CHECK(result_version>0),
                request_version bigint NOT NULL CHECK(request_version>0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                expires_at timestamptz NOT NULL DEFAULT (clock_timestamp()+interval '72 hours'),
                CHECK(expires_at>created_at),
                UNIQUE(actor_id,key_hash)
            );
            CREATE TABLE proposal_documents (
                proposal_id uuid PRIMARY KEY,
                request_id uuid NOT NULL,
                customer_id uuid NOT NULL,
                document_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY(proposal_id,request_id,customer_id) REFERENCES proposals(id,request_id,customer_id) ON DELETE RESTRICT,
                FOREIGN KEY(document_id,customer_id,request_id) REFERENCES documents(id,customer_id,parent_id) ON DELETE RESTRICT
            );
            CREATE INDEX proposal_documents_retained ON proposal_documents(document_id);
            CREATE OR REPLACE FUNCTION proposal_preserve_terms() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Proposals cannot be deleted.' USING ERRCODE='23514'; END IF;
                IF (NEW.id,NEW.request_id,NEW.customer_id,NEW.revision_number,NEW.author_id,NEW.created_at)
                    IS DISTINCT FROM (OLD.id,OLD.request_id,OLD.customer_id,OLD.revision_number,OLD.author_id,OLD.created_at) THEN
                    RAISE EXCEPTION 'Proposal identity is immutable.' USING ERRCODE='23514';
                END IF;
                IF OLD.issued_at IS NOT NULL AND (to_jsonb(NEW)-ARRAY['state','lock_version','updated_at'])
                    IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','lock_version','updated_at']) THEN
                    RAISE EXCEPTION 'Issued commercial terms are immutable.' USING ERRCODE='23514';
                END IF;
                IF OLD.issued_at IS NOT NULL AND NEW.state<>OLD.state AND NOT
                    ((OLD.state='issued' AND NEW.state IN ('accepted','declined','expired','superseded','withdrawn'))
                     OR (OLD.state='accepted' AND NEW.state='rescinded')) THEN
                    RAISE EXCEPTION 'Terminal proposal cannot reopen.' USING ERRCODE='23514';
                END IF;
                IF OLD.state='internally_approved' AND NEW.state='draft' AND NEW.content_version<>OLD.content_version+1 THEN
                    RAISE EXCEPTION 'Invalidating approval must advance the commercial version.' USING ERRCODE='23514';
                END IF;
                IF OLD.issued_at IS NULL AND (NEW.scope_summary,NEW.timeline,NEW.commercial_notes,NEW.pricing_mode,NEW.amount_minor,NEW.currency,NEW.valid_until,NEW.discovery_revision_id)
                    IS DISTINCT FROM (OLD.scope_summary,OLD.timeline,OLD.commercial_notes,OLD.pricing_mode,OLD.amount_minor,OLD.currency,OLD.valid_until,OLD.discovery_revision_id)
                    AND (NEW.content_version<>OLD.content_version+1 OR NEW.state<>'draft' OR NEW.current_approval_id IS NOT NULL) THEN
                    RAISE EXCEPTION 'Editing must invalidate approval and advance content version.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER proposal_terms_guard BEFORE UPDATE OR DELETE ON proposals FOR EACH ROW EXECUTE FUNCTION proposal_preserve_terms();
            CREATE OR REPLACE FUNCTION proposal_guard_child() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent uuid; phase text;
            BEGIN
                parent := CASE WHEN TG_OP='DELETE' THEN OLD.proposal_id ELSE NEW.proposal_id END;
                IF TG_OP='UPDATE' AND NEW.proposal_id<>OLD.proposal_id THEN RAISE EXCEPTION 'Child ownership is immutable.' USING ERRCODE='23514'; END IF;
                SELECT state INTO phase FROM proposals WHERE id=parent FOR UPDATE;
                IF phase<>'draft' THEN RAISE EXCEPTION 'Only draft terms may change.' USING ERRCODE='23514'; END IF;
                IF TG_TABLE_NAME='proposal_documents' AND TG_OP<>'DELETE' THEN
                    PERFORM 1 FROM documents WHERE id=NEW.document_id AND state='available' FOR UPDATE;
                    IF NOT FOUND THEN RAISE EXCEPTION 'Proposal document must be cleared.' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
            END; $$;
            CREATE OR REPLACE FUNCTION proposal_guard_approval() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM 1 FROM proposals p WHERE p.id=NEW.proposal_id AND p.state='draft' AND p.content_version=NEW.content_version
                    AND p.author_id<>NEW.approved_by AND NOT EXISTS(SELECT 1 FROM proposal_contributors c WHERE c.proposal_id=p.id AND c.user_id=NEW.approved_by) FOR UPDATE;
                IF NOT FOUND THEN RAISE EXCEPTION 'Approval requires another actor and current draft version.' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER proposal_approval_guard BEFORE INSERT ON proposal_approvals FOR EACH ROW EXECUTE FUNCTION proposal_guard_approval();
            CREATE OR REPLACE FUNCTION proposal_check_totals() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent uuid; p proposals%ROWTYPE; count_items bigint; total numeric;
            BEGIN
                IF TG_TABLE_NAME='proposals' THEN parent := NEW.id;
                ELSIF TG_OP='DELETE' THEN parent := OLD.proposal_id;
                ELSE parent := NEW.proposal_id; END IF;
                SELECT * INTO p FROM proposals WHERE id=parent;
                IF NOT FOUND THEN RETURN NULL; END IF;
                SELECT count(*),coalesce(sum(line_total_minor::numeric),0) INTO count_items,total FROM proposal_items WHERE proposal_id=parent;
                IF (p.pricing_mode='fixed' AND count_items<>0) OR (p.pricing_mode='items' AND (count_items=0 OR total<>p.amount_minor)) THEN
                    RAISE EXCEPTION 'Proposal totals do not match exact line totals.' USING ERRCODE='23514';
                END IF;
                IF NOT EXISTS(SELECT 1 FROM proposal_deliverables WHERE proposal_id=parent) THEN
                    RAISE EXCEPTION 'Proposal requires deliverables.' USING ERRCODE='23514';
                END IF;
                IF NOT EXISTS(SELECT 1 FROM discovery_revisions WHERE id=p.discovery_revision_id AND state='completed') THEN
                    RAISE EXCEPTION 'Proposal requires completed discovery.' USING ERRCODE='23514';
                END IF;
                IF p.state IN ('accepted','declined','rescinded') AND NOT EXISTS(SELECT 1 FROM proposal_decisions WHERE proposal_id=parent AND decision=p.state) THEN
                    RAISE EXCEPTION 'Customer decision is required.' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER proposal_consistency AFTER INSERT OR UPDATE ON proposals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION proposal_check_totals();
            CREATE OR REPLACE FUNCTION proposal_guard_retained_document() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.state IN ('deleting','deleted') AND EXISTS(SELECT 1 FROM proposal_documents WHERE document_id=NEW.id) THEN
                    RAISE EXCEPTION 'Proposal documents are retained.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER proposal_retained_document BEFORE UPDATE ON documents FOR EACH ROW EXECUTE FUNCTION proposal_guard_retained_document();
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
            CREATE TRIGGER proposal_decision_guard BEFORE INSERT ON proposal_decisions FOR EACH ROW EXECUTE FUNCTION proposal_guard_decision();
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
            CREATE CONSTRAINT TRIGGER proposal_request_consistency AFTER INSERT OR UPDATE ON proposals
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION proposal_check_request_state();
            CREATE CONSTRAINT TRIGGER intake_proposal_consistency AFTER INSERT OR UPDATE ON project_requests
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION proposal_check_request_state();
            DO $$ DECLARE target text; BEGIN
                FOREACH target IN ARRAY ARRAY['proposal_items','proposal_deliverables','proposal_documents'] LOOP
                    EXECUTE format('CREATE TRIGGER proposal_child_guard BEFORE INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION proposal_guard_child()',target);
                    EXECUTE format('CREATE CONSTRAINT TRIGGER proposal_child_consistency AFTER INSERT OR UPDATE OR DELETE ON %I DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION proposal_check_totals()',target);
                END LOOP;
                FOREACH target IN ARRAY ARRAY['proposal_approvals','proposal_decisions','proposal_events','proposal_command_keys','proposal_contributors'] LOOP
                    EXECUTE format('CREATE TRIGGER proposal_history_no_change BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION intake_history_append_only()',target);
                    IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN EXECUTE format('REVOKE UPDATE,DELETE ON %I FROM holoul_app',target); END IF;
                END LOOP;
                FOREACH target IN ARRAY ARRAY['proposals','proposal_series','proposal_items','proposal_deliverables','proposal_documents','proposal_approvals','proposal_decisions','proposal_events','proposal_command_keys','proposal_contributors'] LOOP
                    EXECUTE format('CREATE TRIGGER proposal_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION intake_history_append_only()',target);
                    IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN EXECUTE format('REVOKE TRUNCATE ON %I FROM holoul_app',target); END IF;
                END LOOP;
                IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN GRANT USAGE ON SEQUENCE proposal_reference_sequence TO holoul_app; END IF;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER proposal_retained_document ON documents;
            DROP TRIGGER intake_proposal_consistency ON project_requests;
            ALTER TABLE proposals DROP CONSTRAINT proposals_current_approval_fk;
            DROP TABLE proposal_documents,proposal_command_keys,proposal_events,proposal_decisions,proposal_approvals,proposal_contributors,proposal_deliverables,proposal_items,proposals,proposal_series;
            DROP FUNCTION proposal_preserve_terms(),proposal_guard_child(),proposal_guard_approval(),proposal_check_totals(),proposal_guard_retained_document(),proposal_guard_decision(),proposal_check_request_state();
            SQL);
    }
};
