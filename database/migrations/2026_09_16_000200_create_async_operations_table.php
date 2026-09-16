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
        Schema::create('async_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 80);
            $table->char('logical_key_hash', 64);
            $table->char('input_hash', 64);
            $table->jsonb('references');
            $table->uuid('request_id')->nullable();
            $table->string('state', 16);
            $table->smallInteger('attempts')->default(0);
            $table->smallInteger('max_attempts')->default(5);
            $table->bigInteger('fence')->default(0);
            $table->timestampTz('next_attempt_at', 6);
            $table->timestampTz('lease_expires_at', 6)->nullable();
            $table->timestampTz('last_dispatched_at', 6)->nullable();
            $table->timestampTz('completed_at', 6)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
            $table->unique(['kind', 'logical_key_hash']);
            $table->index(['state', 'next_attempt_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE async_operations
              ADD CONSTRAINT async_operation_uuid_v7 CHECK (substring(id::text, 15, 1) = '7'),
              ADD CONSTRAINT async_operation_kind CHECK (kind ~ '^[a-z][a-z0-9_.]{0,79}$'),
              ADD CONSTRAINT async_operation_hashes CHECK (logical_key_hash ~ '^[a-f0-9]{64}$' AND input_hash ~ '^[a-f0-9]{64}$'),
              ADD CONSTRAINT async_operation_references CHECK (jsonb_typeof("references") = 'object' AND octet_length("references"::text) <= 2048),
              ADD CONSTRAINT async_operation_state CHECK (state IN ('pending', 'running', 'succeeded', 'failed')),
              ADD CONSTRAINT async_operation_attempts CHECK (attempts >= 0 AND max_attempts BETWEEN 1 AND 20 AND attempts <= max_attempts AND fence >= 0),
              ADD CONSTRAINT async_operation_lease CHECK ((state = 'running') = (lease_expires_at IS NOT NULL)),
              ADD CONSTRAINT async_operation_terminal CHECK ((state IN ('succeeded', 'failed')) = (completed_at IS NOT NULL)),
              ADD CONSTRAINT async_operation_failure_code CHECK (failure_code IS NULL OR failure_code ~ '^[a-z][a-z0-9_]{0,63}$'),
              ADD CONSTRAINT async_operation_outcome CHECK ((state <> 'failed' OR failure_code IS NOT NULL) AND (state <> 'succeeded' OR failure_code IS NULL))
            SQL);

        DB::statement("CREATE INDEX async_operations_expired_lease ON async_operations (lease_expires_at) WHERE state = 'running'");
    }

    public function down(): void
    {
        Schema::dropIfExists('async_operations');
    }
};
