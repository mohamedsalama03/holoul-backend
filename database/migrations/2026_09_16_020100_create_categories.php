<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('slug', 80)->unique();
            $table->boolean('active')->default(true);
            $table->integer('display_order')->default(0);
            $table->bigInteger('lock_version')->default(1);
            $table->timestampsTz(6);
            $table->index(['active', 'display_order', 'id']);
            $table->index(['display_order', 'id']);
        });
        Schema::create('subcategories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained('categories')->restrictOnDelete()->restrictOnUpdate();
            $table->string('name', 160);
            $table->string('slug', 80);
            $table->boolean('active')->default(true);
            $table->integer('display_order')->default(0);
            $table->bigInteger('lock_version')->default(1);
            $table->timestampsTz(6);
            $table->unique(['category_id', 'slug']);
            $table->unique(['id', 'category_id']);
            $table->index(['category_id', 'active', 'display_order', 'id']);
            $table->index(['category_id', 'display_order', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE categories
                ADD CONSTRAINT categories_uuid_v7 CHECK (substring(id::text, 15, 1) = '7'),
                ADD CONSTRAINT categories_name CHECK (length(btrim(name)) BETWEEN 1 AND 160 AND name !~ '[[:cntrl:]]'),
                ADD CONSTRAINT categories_slug CHECK (slug ~ '^[a-z][a-z0-9]*(-[a-z0-9]+)*$'),
                ADD CONSTRAINT categories_order CHECK (display_order BETWEEN 0 AND 1000000),
                ADD CONSTRAINT categories_version CHECK (lock_version > 0);
            ALTER TABLE subcategories
                ADD CONSTRAINT subcategories_uuid_v7 CHECK (substring(id::text, 15, 1) = '7'),
                ADD CONSTRAINT subcategories_name CHECK (length(btrim(name)) BETWEEN 1 AND 160 AND name !~ '[[:cntrl:]]'),
                ADD CONSTRAINT subcategories_slug CHECK (slug ~ '^[a-z][a-z0-9]*(-[a-z0-9]+)*$'),
                ADD CONSTRAINT subcategories_order CHECK (display_order BETWEEN 0 AND 1000000),
                ADD CONSTRAINT subcategories_version CHECK (lock_version > 0);

            CREATE OR REPLACE FUNCTION taxonomy_preserve_identity() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.slug IS DISTINCT FROM OLD.slug
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Taxonomy identity is immutable.' USING ERRCODE = '23514';
                END IF;
                IF TG_TABLE_NAME = 'subcategories' THEN
                    IF NEW.category_id IS DISTINCT FROM OLD.category_id THEN
                        RAISE EXCEPTION 'Taxonomy parent is immutable.' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF NEW.lock_version <> OLD.lock_version + 1 THEN
                    RAISE EXCEPTION 'Taxonomy edits must advance the version.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION taxonomy_reject_deletion() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Deactivate taxonomy instead of deleting it.' USING ERRCODE = '55000';
            END;
            $$;

            CREATE TRIGGER categories_identity BEFORE UPDATE ON categories
                FOR EACH ROW EXECUTE FUNCTION taxonomy_preserve_identity();
            CREATE TRIGGER subcategories_identity BEFORE UPDATE ON subcategories
                FOR EACH ROW EXECUTE FUNCTION taxonomy_preserve_identity();
            CREATE TRIGGER categories_no_delete BEFORE DELETE ON categories
                FOR EACH ROW EXECUTE FUNCTION taxonomy_reject_deletion();
            CREATE TRIGGER subcategories_no_delete BEFORE DELETE ON subcategories
                FOR EACH ROW EXECUTE FUNCTION taxonomy_reject_deletion();
            CREATE TRIGGER categories_no_truncate BEFORE TRUNCATE ON categories
                FOR EACH STATEMENT EXECUTE FUNCTION taxonomy_reject_deletion();
            CREATE TRIGGER subcategories_no_truncate BEFORE TRUNCATE ON subcategories
                FOR EACH STATEMENT EXECUTE FUNCTION taxonomy_reject_deletion();

            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'holoul_app') THEN
                    REVOKE DELETE, TRUNCATE ON categories, subcategories FROM holoul_app;
                    GRANT SELECT, INSERT, UPDATE ON categories, subcategories TO holoul_app;
                END IF;
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('subcategories');
        Schema::dropIfExists('categories');
        DB::unprepared('DROP FUNCTION IF EXISTS taxonomy_preserve_identity()');
        DB::unprepared('DROP FUNCTION IF EXISTS taxonomy_reject_deletion()');
    }
};
