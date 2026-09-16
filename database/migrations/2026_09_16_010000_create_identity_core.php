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
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('full_name', 160);
            $table->string('email', 254)->unique();
            $table->string('email_display', 254);
            $table->string('password');
            $table->string('kind', 16);
            $table->boolean('enabled')->default(true);
            $table->timestampTz('email_verified_at')->nullable();
            $table->bigInteger('auth_version')->default(1);
            $table->timestampsTz();
            $table->unique(['id', 'kind']);
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_identity_shape CHECK (kind IN ('customer','staff') AND auth_version > 0 AND email = lower(btrim(email)) AND length(email) > 3 AND length(btrim(full_name)) > 0 AND substring(id::text,15,1) = '7')");
        // B1 had no authenticated identities. Existing anonymous sessions remain valid.
        Schema::table('sessions', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::create('identity_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->char('session_hash', 64)->unique();
            $table->bigInteger('auth_version');
            $table->timestampTz('authenticated_at');
            $table->timestampTz('last_activity_at');
            $table->timestampTz('expires_at')->index();
            $table->index(['user_id', 'last_activity_at']);
        });
        DB::statement("ALTER TABLE identity_sessions ADD CONSTRAINT identity_sessions_shape CHECK (auth_version > 0 AND expires_at > authenticated_at AND substring(id::text,15,1) = '7' AND session_hash ~ '^[a-f0-9]{64}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_sessions');
        Schema::table('sessions', fn (Blueprint $table) => $table->dropForeign(['user_id']));
        Schema::dropIfExists('users');
    }
};
