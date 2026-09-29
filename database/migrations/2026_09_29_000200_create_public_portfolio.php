<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE portfolio_projects (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'), public_id uuid NOT NULL UNIQUE,
                title varchar(120) NOT NULL, summary varchar(240) NOT NULL, description text NOT NULL,
                category_id varchar(16) NOT NULL CHECK(category_id IN ('web','mobile','business','commerce','custom')),
                cover_image_id uuid, featured_image_id uuid, publication_id uuid,
                lock_version bigint NOT NULL DEFAULT 1 CHECK(lock_version>0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CHECK(char_length(title) BETWEEN 2 AND 120 AND char_length(summary) BETWEEN 10 AND 240 AND char_length(description) BETWEEN 30 AND 12000),
                CHECK(public_id=id OR substring(public_id::text,15,1)='4')
            );
            CREATE TABLE portfolio_assets (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'), project_id uuid NOT NULL REFERENCES portfolio_projects(id) ON DELETE RESTRICT,
                owner_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                state varchar(16) NOT NULL CHECK(state IN ('reserved','processing','ready','rejected','removed','expired')),
                media_type varchar(16) NOT NULL CHECK(media_type IN ('image/jpeg','image/png','image/webp')),
                byte_size int NOT NULL CHECK(byte_size BETWEEN 1 AND 5242880), sha256 char(64) NOT NULL CHECK(sha256 ~ '^[0-9a-f]{64}$'),
                alt varchar(240) NOT NULL CHECK(char_length(alt) BETWEEN 1 AND 240), display_order smallint NOT NULL CHECK(display_order BETWEEN 0 AND 7),
                source_version text, retired_at timestamptz, purged_at timestamptz, variants jsonb NOT NULL DEFAULT '{}'::jsonb, failure_code varchar(48),
                operation_id uuid UNIQUE REFERENCES async_operations(id) ON DELETE RESTRICT,
                lock_version bigint NOT NULL DEFAULT 1 CHECK(lock_version>0),
                expires_at timestamptz NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(project_id,id), CHECK(state<>'ready' OR (source_version IS NOT NULL AND variants ? 'card' AND variants ? 'gallery'))
            );
            CREATE INDEX portfolio_asset_active ON portfolio_assets(project_id,display_order,id) WHERE state NOT IN ('removed','expired');
            ALTER TABLE portfolio_projects ADD FOREIGN KEY(id,cover_image_id) REFERENCES portfolio_assets(project_id,id) DEFERRABLE INITIALLY DEFERRED;
            ALTER TABLE portfolio_projects ADD FOREIGN KEY(id,featured_image_id) REFERENCES portfolio_assets(project_id,id) DEFERRABLE INITIALLY DEFERRED;
            CREATE TABLE portfolio_publications (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'), project_id uuid NOT NULL REFERENCES portfolio_projects(id) ON DELETE RESTRICT,
                category_id varchar(16) NOT NULL CHECK(category_id IN ('web','mobile','business','commerce','custom')),
                payload jsonb NOT NULL CHECK(jsonb_typeof(payload)='object'),
                published_at timestamptz NOT NULL, actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                UNIQUE(project_id,id)
            );
            ALTER TABLE portfolio_projects ADD FOREIGN KEY(id,publication_id) REFERENCES portfolio_publications(project_id,id) DEFERRABLE INITIALLY DEFERRED;
            CREATE INDEX portfolio_current_publication ON portfolio_projects(publication_id) WHERE publication_id IS NOT NULL;
            CREATE INDEX portfolio_publication_order ON portfolio_publications(published_at DESC,id DESC);
            CREATE INDEX portfolio_publication_category_order ON portfolio_publications(category_id,published_at DESC,id DESC);
            CREATE TABLE portfolio_public_images (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'), project_id uuid NOT NULL,
                publication_id uuid NOT NULL, asset_id uuid NOT NULL,
                FOREIGN KEY(project_id,publication_id) REFERENCES portfolio_publications(project_id,id) ON DELETE RESTRICT,
                FOREIGN KEY(project_id,asset_id) REFERENCES portfolio_assets(project_id,id) ON DELETE RESTRICT,
                UNIQUE(publication_id,asset_id)
            );
            CREATE TABLE portfolio_command_keys (
                key_hash char(64) PRIMARY KEY, input_hash char(64) NOT NULL, response json NOT NULL,
                project_id uuid NOT NULL REFERENCES portfolio_projects(id) ON DELETE RESTRICT, created_at timestamptz NOT NULL DEFAULT clock_timestamp()
            );
            CREATE TABLE portfolio_invalidations (
                id uuid PRIMARY KEY CHECK(substring(id::text,15,1)='7'), project_id uuid NOT NULL REFERENCES portfolio_projects(id) ON DELETE RESTRICT,
                operation_id uuid UNIQUE REFERENCES async_operations(id) ON DELETE RESTRICT,
                publication_id uuid REFERENCES portfolio_publications(id), origin_applied_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp()
            );
            CREATE TABLE portfolio_imports (
                source_id uuid PRIMARY KEY CHECK(substring(source_id::text,15,1)='4'), project_id uuid NOT NULL UNIQUE REFERENCES portfolio_projects(id),
                manifest_hash char(64) NOT NULL, source_status varchar(16) NOT NULL CHECK(source_status IN ('draft','published')), image_map jsonb NOT NULL, imported_at timestamptz NOT NULL DEFAULT clock_timestamp()
            );
            CREATE OR REPLACE FUNCTION portfolio_project_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP<>'UPDATE' THEN RAISE EXCEPTION 'Portfolio history cannot be deleted.' USING ERRCODE='55000'; END IF;
                IF NEW.id<>OLD.id OR NEW.public_id<>OLD.public_id OR NEW.created_at<>OLD.created_at OR NEW.lock_version<>OLD.lock_version+1 THEN
                    RAISE EXCEPTION 'Portfolio identity and version are immutable.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER portfolio_project_identity BEFORE UPDATE OR DELETE ON portfolio_projects FOR EACH ROW EXECUTE FUNCTION portfolio_project_guard();
            CREATE OR REPLACE FUNCTION portfolio_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Portfolio publication history is append only.' USING ERRCODE='55000'; END; $$;
            CREATE TRIGGER portfolio_publication_guard BEFORE UPDATE OR DELETE ON portfolio_publications FOR EACH ROW EXECUTE FUNCTION portfolio_immutable();
            CREATE TRIGGER portfolio_publication_truncate BEFORE TRUNCATE ON portfolio_publications FOR EACH STATEMENT EXECUTE FUNCTION portfolio_immutable();
            CREATE TRIGGER portfolio_image_guard BEFORE UPDATE OR DELETE ON portfolio_public_images FOR EACH ROW EXECUTE FUNCTION portfolio_immutable();
            CREATE TRIGGER portfolio_image_truncate BEFORE TRUNCATE ON portfolio_public_images FOR EACH STATEMENT EXECUTE FUNCTION portfolio_immutable();
            CREATE OR REPLACE FUNCTION portfolio_asset_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP<>'UPDATE' THEN RAISE EXCEPTION 'Portfolio asset history cannot be deleted.' USING ERRCODE='55000'; END IF;
                IF NEW.id<>OLD.id OR NEW.project_id<>OLD.project_id OR NEW.owner_id<>OLD.owner_id OR NEW.sha256<>OLD.sha256 OR NEW.byte_size<>OLD.byte_size
                    OR NEW.media_type<>OLD.media_type OR NEW.lock_version<>OLD.lock_version+1 OR NEW.alt<>OLD.alt OR NEW.display_order<>OLD.display_order
                    OR (OLD.source_version IS NOT NULL AND NEW.source_version IS DISTINCT FROM OLD.source_version)
                    OR (OLD.state IN ('ready','removed','expired','rejected') AND NEW.variants IS DISTINCT FROM OLD.variants)
                    OR (OLD.state IN ('removed','expired') AND NEW.state<>OLD.state)
                    OR (OLD.state='ready' AND NEW.state NOT IN ('ready','removed'))
                    OR (OLD.state='rejected' AND NEW.state NOT IN ('rejected','removed')) THEN
                    RAISE EXCEPTION 'Portfolio asset identity and processed bytes are immutable.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER portfolio_asset_immutable BEFORE UPDATE OR DELETE ON portfolio_assets FOR EACH ROW EXECUTE FUNCTION portfolio_asset_guard();
            REVOKE ALL ON portfolio_projects,portfolio_assets,portfolio_publications,portfolio_public_images,portfolio_command_keys,portfolio_invalidations,portfolio_imports FROM holoul_app;
            GRANT SELECT,INSERT,UPDATE ON portfolio_projects,portfolio_assets,portfolio_invalidations TO holoul_app;
            GRANT SELECT,INSERT ON portfolio_publications,portfolio_public_images,portfolio_command_keys,portfolio_imports TO holoul_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            LOCK TABLE portfolio_projects IN ACCESS EXCLUSIVE MODE;
            DO $$ BEGIN
                IF EXISTS(SELECT 1 FROM portfolio_projects) THEN
                    RAISE EXCEPTION 'Portfolio history requires reviewed forward recovery.' USING ERRCODE='55000';
                END IF;
            END $$;
            ALTER TABLE portfolio_projects DROP CONSTRAINT portfolio_projects_id_cover_image_id_fkey;
            ALTER TABLE portfolio_projects DROP CONSTRAINT portfolio_projects_id_featured_image_id_fkey;
            ALTER TABLE portfolio_projects DROP CONSTRAINT portfolio_projects_id_publication_id_fkey;
            DROP TABLE portfolio_imports,portfolio_invalidations,portfolio_command_keys,portfolio_public_images,portfolio_publications,portfolio_assets,portfolio_projects;
            DROP FUNCTION portfolio_immutable(),portfolio_asset_guard(),portfolio_project_guard();
            SQL);
    }
};
