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
        Schema::create('currencies', function (Blueprint $table): void {
            $table->char('code', 3)->primary();
            $table->smallInteger('exponent');
        });
        DB::statement("ALTER TABLE currencies ADD CONSTRAINT currencies_supported CHECK ((code = 'USD' AND exponent = 2) OR (code = 'LYD' AND exponent = 3))");
        DB::table('currencies')->insert([['code' => 'USD', 'exponent' => 2], ['code' => 'LYD', 'exponent' => 3]]);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION currencies_reject_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'The supported currency catalog is read-only.' USING ERRCODE = '55000';
            END;
            $$;

            CREATE TRIGGER currencies_no_write BEFORE INSERT OR UPDATE OR DELETE ON currencies
                FOR EACH STATEMENT EXECUTE FUNCTION currencies_reject_mutation();
            CREATE TRIGGER currencies_no_truncate BEFORE TRUNCATE ON currencies
                FOR EACH STATEMENT EXECUTE FUNCTION currencies_reject_mutation();

            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'holoul_app') THEN
                    REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON currencies FROM holoul_app;
                    GRANT SELECT ON currencies TO holoul_app;
                END IF;
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
        DB::unprepared('DROP FUNCTION IF EXISTS currencies_reject_mutation()');
    }
};
