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
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->string('customer_kind', 16)->default('customer');
            $table->string('phone_e164', 16);
            $table->string('phone_display', 64);
            $table->timestampsTz(6);
            $table->foreign(['user_id', 'customer_kind'])->references(['id', 'kind'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE customers
                ADD CONSTRAINT customers_uuid_v7 CHECK (substring(id::text, 15, 1) = '7'),
                ADD CONSTRAINT customers_persona CHECK (customer_kind = 'customer'),
                ADD CONSTRAINT customers_phone_e164 CHECK (phone_e164 ~ '^\+[1-9][0-9]{1,14}$'),
                ADD CONSTRAINT customers_phone_display CHECK (phone_display ~ '^\+[0-9 ().-]+$');

            CREATE OR REPLACE FUNCTION customers_preserve_owner() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.customer_kind IS DISTINCT FROM OLD.customer_kind THEN
                    RAISE EXCEPTION 'Customer ownership is immutable.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER customers_immutable_owner BEFORE UPDATE ON customers
                FOR EACH ROW EXECUTE FUNCTION customers_preserve_owner();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
        DB::unprepared('DROP FUNCTION IF EXISTS customers_preserve_owner()');
    }
};
