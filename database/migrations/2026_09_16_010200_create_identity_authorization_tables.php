<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 48)->unique();
            $table->string('name', 64);
            $table->string('kind', 16);
            $table->unique(['id', 'kind']);
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->restrictOnDelete();
        });
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->uuid('role_id');
            $table->string('user_kind', 16);
            $table->primary(['user_id', 'role_id']);
            $table->foreign(['user_id', 'user_kind'])->references(['id', 'kind'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['role_id', 'user_kind'])->references(['id', 'kind'])->on('roles')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE roles ADD CONSTRAINT roles_uuid_v7 CHECK (substring(id::text, 15, 1) = '7'),
                ADD CONSTRAINT roles_persona CHECK (kind IN ('customer', 'staff'));
            ALTER TABLE permissions ADD CONSTRAINT permissions_uuid_v7 CHECK (substring(id::text, 15, 1) = '7');
            SQL);

        $permissions = [];

        foreach (['identity.self.read', 'identity.self.update', 'customers.self.read', 'customers.self.update', 'identity.staff.read', 'identity.staff.manage', 'identity.security.manage'] as $code) {
            $permissions[$code] = Str::uuid7()->toString();
            DB::table('permissions')->insert(['id' => $permissions[$code], 'code' => $code]);
        }

        foreach ([
            'super_admin' => 'Super Admin', 'administrator' => 'Administrator', 'project_manager' => 'Project Manager',
            'business_analyst' => 'Business Analyst', 'sales' => 'Sales', 'reviewer' => 'Reviewer', 'support' => 'Support', 'customer' => 'Customer',
        ] as $code => $name) {
            $id = Str::uuid7()->toString();
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'name' => $name, 'kind' => $code === 'customer' ? 'customer' : 'staff']);
            $grants = ['identity.self.read', 'identity.self.update'];

            if ($code === 'customer') {
                $grants = [...$grants, 'customers.self.read', 'customers.self.update'];
            }

            if (in_array($code, ['super_admin', 'administrator'], true)) {
                $grants = [...$grants, 'identity.staff.read', 'identity.staff.manage'];
            }

            if ($code === 'super_admin') {
                $grants[] = 'identity.security.manage';
            }

            foreach ($grants as $permission) {
                DB::table('role_permissions')->insert(['role_id' => $id, 'permission_id' => $permissions[$permission]]);
            }
        }

        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'holoul_app') THEN
                    REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON roles, permissions, role_permissions FROM holoul_app;
                    GRANT SELECT ON roles, permissions, role_permissions TO holoul_app;
                END IF;
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
