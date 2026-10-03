<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users ADD COLUMN username varchar(40) UNIQUE;
            ALTER TABLE users ADD CONSTRAINT users_username_shape CHECK (
                username IS NULL OR (kind='staff' AND username ~ '^[a-z][a-z0-9._-]{2,39}$'));
            CREATE OR REPLACE FUNCTION identity_username_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.username IS DISTINCT FROM NEW.username THEN
                    RAISE EXCEPTION 'Username changes require an explicit account migration.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER identity_username_guard BEFORE UPDATE OF username ON users
                FOR EACH ROW EXECUTE FUNCTION identity_username_guard();
            CREATE TABLE identity_staff_creation_keys (
                actor_id uuid NOT NULL REFERENCES users(id), key_hash char(64) NOT NULL CHECK (key_hash ~ '^[a-f0-9]{64}$'),
                input_hash char(64) NOT NULL CHECK (input_hash ~ '^[a-f0-9]{64}$'),
                user_id uuid NOT NULL UNIQUE REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                PRIMARY KEY(actor_id,key_hash)
            );
            CREATE OR REPLACE FUNCTION identity_staff_creation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Staff creation receipts are immutable.' USING ERRCODE='23514';
            END $$;
            CREATE TRIGGER identity_staff_creation_guard BEFORE UPDATE OR DELETE OR TRUNCATE ON identity_staff_creation_keys
                FOR EACH STATEMENT EXECUTE FUNCTION identity_staff_creation_guard();
            DO $$ BEGIN
                IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='holoul_app') THEN
                    GRANT SELECT,INSERT ON identity_staff_creation_keys TO holoul_app;
                    REVOKE UPDATE,DELETE,TRUNCATE ON identity_staff_creation_keys FROM holoul_app;
                END IF;
            END $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                IF EXISTS(SELECT 1 FROM identity_staff_creation_keys) OR EXISTS(SELECT 1 FROM users WHERE username IS NOT NULL) THEN
                    RAISE EXCEPTION 'Retained staff accounts require a forward migration.' USING ERRCODE='55000';
                END IF;
            END $$;
            DROP TABLE identity_staff_creation_keys;
            DROP FUNCTION identity_staff_creation_guard();
            DROP TRIGGER identity_username_guard ON users;
            DROP FUNCTION identity_username_guard();
            ALTER TABLE users DROP COLUMN username;
            SQL);
    }
};
