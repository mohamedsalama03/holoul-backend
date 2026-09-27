<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users ADD COLUMN authorization_revision bigint NOT NULL DEFAULT 1 CHECK (authorization_revision > 0);
            CREATE TABLE identity_staff_invitations (
                id uuid PRIMARY KEY, email varchar(254) NOT NULL, full_name varchar(160) NOT NULL,
                invited_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                inviter_name varchar(160) NOT NULL, roles_sealed boolean NOT NULL DEFAULT false,
                status varchar(16) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','accepted','expired','revoked')),
                token_hash char(64) UNIQUE, generation integer NOT NULL DEFAULT 1 CHECK (generation > 0),
                lock_version bigint NOT NULL DEFAULT 1 CHECK (lock_version > 0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                expires_at timestamptz NOT NULL, last_requested_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                last_sent_at timestamptz, send_count integer NOT NULL DEFAULT 0 CHECK (send_count >= 0),
                accepted_at timestamptz, accepted_user_id uuid UNIQUE REFERENCES users(id) ON DELETE RESTRICT,
                activated_at timestamptz, revoked_at timestamptz,
                staff_kind varchar(16) NOT NULL DEFAULT 'staff' CHECK(staff_kind='staff'),
                FOREIGN KEY(accepted_user_id,staff_kind) REFERENCES users(id,kind) ON DELETE RESTRICT,
                CHECK (email = lower(btrim(email))), CHECK (expires_at > created_at),
                CHECK ((status = 'accepted') = (accepted_at IS NOT NULL AND accepted_user_id IS NOT NULL)),
                CHECK ((status = 'revoked') = (revoked_at IS NOT NULL)),
                CHECK (activated_at IS NULL OR status = 'accepted'),
                CHECK (status = 'pending' OR token_hash IS NULL),
                CHECK(token_hash IS NULL OR token_hash ~ '^[a-f0-9]{64}$'), CHECK(substring(id::text,15,1)='7')
            );
            CREATE UNIQUE INDEX identity_staff_invitation_pending_email ON identity_staff_invitations(email) WHERE status = 'pending';
            CREATE INDEX identity_staff_invitation_expiry ON identity_staff_invitations(expires_at) WHERE status = 'pending';
            CREATE TABLE identity_staff_invitation_roles (
                invitation_id uuid NOT NULL REFERENCES identity_staff_invitations(id) ON DELETE RESTRICT,
                role_id uuid NOT NULL, staff_kind varchar(16) NOT NULL DEFAULT 'staff' CHECK(staff_kind='staff'),
                FOREIGN KEY(role_id,staff_kind) REFERENCES roles(id,kind) ON DELETE RESTRICT,
                PRIMARY KEY (invitation_id, role_id)
            );
            CREATE TABLE identity_staff_invitation_keys (
                actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                key_hash char(64) NOT NULL, input_hash char(64) NOT NULL,
                invitation_id uuid NOT NULL REFERENCES identity_staff_invitations(id) ON DELETE RESTRICT,
                PRIMARY KEY(actor_id, key_hash)
            );
            CREATE TABLE identity_staff_invitation_mail (
                id uuid PRIMARY KEY, invitation_id uuid NOT NULL REFERENCES identity_staff_invitations(id) ON DELETE RESTRICT,
                operation_id uuid NOT NULL UNIQUE REFERENCES async_operations(id) ON DELETE RESTRICT,
                generation integer NOT NULL CHECK (generation > 0),
                state varchar(16) NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','sending','sent','uncertain','discarded')),
                send_fence bigint, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), sent_at timestamptz,
                UNIQUE (invitation_id,generation)
            );
            CREATE OR REPLACE FUNCTION identity_staff_invitation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (NEW.id,NEW.email,NEW.full_name,NEW.invited_by,NEW.inviter_name,NEW.created_at)
                    IS DISTINCT FROM (OLD.id,OLD.email,OLD.full_name,OLD.invited_by,OLD.inviter_name,OLD.created_at)
                    OR (OLD.roles_sealed AND NOT NEW.roles_sealed)
                    OR (OLD.status <> 'pending' AND NEW.status <> OLD.status)
                    OR (OLD.accepted_user_id IS NOT NULL AND NEW.accepted_user_id IS DISTINCT FROM OLD.accepted_user_id)
                    OR (OLD.accepted_at IS NOT NULL AND NEW.accepted_at IS DISTINCT FROM OLD.accepted_at)
                    OR (OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at)
                    OR (OLD.activated_at IS NOT NULL AND NEW.activated_at IS DISTINCT FROM OLD.activated_at)
                    OR NEW.lock_version < OLD.lock_version OR NEW.generation < OLD.generation THEN
                    RAISE EXCEPTION 'Invitation identity and terminal history are retained.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER identity_staff_invitation_guard BEFORE UPDATE ON identity_staff_invitations
                FOR EACH ROW EXECUTE FUNCTION identity_staff_invitation_guard();
            CREATE OR REPLACE FUNCTION identity_staff_invitation_role_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Offered roles are immutable.' USING ERRCODE='23514'; END IF;
                IF NOT EXISTS(SELECT 1 FROM identity_staff_invitations WHERE id=NEW.invitation_id AND NOT roles_sealed AND status='pending') THEN
                    RAISE EXCEPTION 'Offered roles are sealed.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER identity_staff_invitation_role_guard BEFORE INSERT OR UPDATE OR DELETE ON identity_staff_invitation_roles
                FOR EACH ROW EXECUTE FUNCTION identity_staff_invitation_role_guard();
            CREATE OR REPLACE FUNCTION identity_staff_invitation_complete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS(SELECT 1 FROM identity_staff_invitations i WHERE i.id=NEW.id AND i.roles_sealed
                    AND (SELECT count(*) FROM identity_staff_invitation_roles r WHERE r.invitation_id=i.id) BETWEEN 1 AND 7) THEN
                    RAISE EXCEPTION 'Invitation roles must be complete at commit.' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER identity_staff_invitation_complete AFTER INSERT OR UPDATE ON identity_staff_invitations
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION identity_staff_invitation_complete();
            DO $$ BEGIN
                IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN
                    GRANT SELECT,INSERT,UPDATE ON identity_staff_invitations,identity_staff_invitation_mail TO holoul_app;
                    GRANT SELECT,INSERT ON identity_staff_invitation_roles,identity_staff_invitation_keys TO holoul_app;
                    REVOKE DELETE,TRUNCATE ON identity_staff_invitations,identity_staff_invitation_mail,identity_staff_invitation_roles,identity_staff_invitation_keys FROM holoul_app;
                    REVOKE UPDATE ON identity_staff_invitation_roles,identity_staff_invitation_keys FROM holoul_app;
                END IF;
            END $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                IF EXISTS(SELECT 1 FROM identity_staff_invitations) THEN
                    RAISE EXCEPTION 'Retained invitation history requires a forward migration.' USING ERRCODE='55000';
                END IF;
            END $$;
            DROP TABLE identity_staff_invitation_mail,identity_staff_invitation_keys,identity_staff_invitation_roles,identity_staff_invitations;
            DROP FUNCTION identity_staff_invitation_guard(),identity_staff_invitation_role_guard(),identity_staff_invitation_complete();
            ALTER TABLE users DROP COLUMN authorization_revision;
            SQL);
    }
};
