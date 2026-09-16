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
        Schema::create('identity_mfa', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->string('staff_kind', 16)->default('staff');
            $table->foreign(['user_id', 'staff_kind'])->references(['id', 'kind'])->on('users')->cascadeOnDelete()->restrictOnUpdate();
            $table->text('secret')->nullable();
            $table->text('pending_secret')->nullable();
            $table->timestampTz('pending_expires_at', 6)->nullable();
            $table->timestampTz('confirmed_at', 6)->nullable();
            $table->bigInteger('last_accepted_step')->nullable();
            $table->timestampsTz(6);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE identity_mfa
                ADD CONSTRAINT identity_mfa_uuid_v7 CHECK (substring(id::text, 15, 1) = '7'),
                ADD CONSTRAINT identity_mfa_staff CHECK (staff_kind = 'staff'),
                ADD CONSTRAINT identity_mfa_secret_state CHECK (
                    (secret IS NULL AND confirmed_at IS NULL AND last_accepted_step IS NULL)
                    OR (secret IS NOT NULL AND confirmed_at IS NOT NULL AND last_accepted_step IS NOT NULL AND last_accepted_step >= 0)
                ),
                ADD CONSTRAINT identity_mfa_pending_state CHECK ((pending_secret IS NULL) = (pending_expires_at IS NULL)),
                ADD CONSTRAINT identity_mfa_enrollment_state CHECK (secret IS NULL OR pending_secret IS NULL),
                ADD CONSTRAINT identity_mfa_secret_length CHECK (octet_length(secret) <= 2048 AND octet_length(pending_secret) <= 2048)
            SQL);

        Schema::create('identity_mfa_recovery_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('mfa_id')->constrained('identity_mfa')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestampTz('consumed_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->index(['mfa_id', 'consumed_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE identity_mfa_recovery_codes
                ADD CONSTRAINT identity_mfa_recovery_uuid_v7 CHECK (substring(id::text, 15, 1) = '7'),
                ADD CONSTRAINT identity_mfa_recovery_hash CHECK (code_hash ~ '^[0-9a-f]{64}$')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_mfa_recovery_codes');
        Schema::dropIfExists('identity_mfa');
    }
};
