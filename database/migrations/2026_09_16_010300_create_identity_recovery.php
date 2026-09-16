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
        Schema::create('identity_recovery_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 24);
            $table->char('token_hash', 64)->unique();
            $table->char('email_hash', 64);
            $table->bigInteger('auth_version');
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('consumed_at', 6)->nullable();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
            $table->unique(['id', 'user_id']);
            $table->index(['user_id', 'purpose']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE identity_recovery_tokens
              ADD CONSTRAINT recovery_token_uuid_v7 CHECK (substring(id::text, 15, 1) = '7'),
              ADD CONSTRAINT recovery_token_purpose CHECK (purpose IN ('verify_email', 'password_reset')),
              ADD CONSTRAINT recovery_token_hashes CHECK (token_hash ~ '^[a-f0-9]{64}$' AND email_hash ~ '^[a-f0-9]{64}$'),
              ADD CONSTRAINT recovery_token_version CHECK (auth_version >= 0),
              ADD CONSTRAINT recovery_token_expiry CHECK (expires_at > created_at),
              ADD CONSTRAINT recovery_token_consumption CHECK (consumed_at IS NULL OR revoked_at IS NULL)
            SQL);
        DB::statement('CREATE UNIQUE INDEX identity_recovery_one_active ON identity_recovery_tokens (user_id, purpose) WHERE consumed_at IS NULL AND revoked_at IS NULL');

        Schema::create('identity_recovery_mail', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('recovery_token_id')->unique();
            $table->foreign(['recovery_token_id', 'user_id'])->references(['id', 'user_id'])->on('identity_recovery_tokens')->restrictOnDelete();
            $table->foreignUuid('operation_id')->unique()->constrained('async_operations')->restrictOnDelete();
            $table->string('state', 16);
            $table->text('encrypted_payload')->nullable();
            $table->bigInteger('send_fence')->nullable();
            $table->timestampTz('send_started_at', 6)->nullable();
            $table->timestampTz('sent_at', 6)->nullable();
            $table->string('failure_code', 40)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
            $table->index(['user_id', 'state']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE identity_recovery_mail
              ADD CONSTRAINT recovery_mail_uuid_v7 CHECK (substring(id::text, 15, 1) = '7'),
              ADD CONSTRAINT recovery_mail_state CHECK (state IN ('pending', 'sending', 'sent', 'discarded', 'uncertain')),
              ADD CONSTRAINT recovery_mail_payload CHECK ((state IN ('pending', 'sending')) = (encrypted_payload IS NOT NULL) AND (encrypted_payload IS NULL OR octet_length(encrypted_payload) <= 8192)),
              ADD CONSTRAINT recovery_mail_sent CHECK ((state = 'sent') = (sent_at IS NOT NULL)),
              ADD CONSTRAINT recovery_mail_send_claim CHECK ((state IN ('sending', 'sent', 'uncertain')) = (send_started_at IS NOT NULL AND send_fence IS NOT NULL AND send_fence >= 1)),
              ADD CONSTRAINT recovery_mail_failure CHECK (failure_code IS NULL OR failure_code ~ '^[a-z][a-z0-9_]{0,39}$')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_recovery_mail');
        Schema::dropIfExists('identity_recovery_tokens');
    }
};
