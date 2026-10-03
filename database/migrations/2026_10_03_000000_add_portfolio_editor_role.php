<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $id = (string) Str::uuid7();
        DB::table('roles')->insert(['id' => $id, 'code' => 'portfolio_editor', 'name' => 'Portfolio Editor', 'kind' => 'staff']);
        foreach (['identity.self.read', 'identity.self.update', 'notifications.self.read', 'notifications.self.manage',
            'portfolio.read', 'portfolio.manage', 'portfolio.publish'] as $code) {
            DB::table('role_permissions')->insert(['role_id' => $id, 'permission_id' => DB::table('permissions')->where('code', $code)->sole()->id]);
        }
        $this->invitationLimit(8);
    }

    public function down(): void
    {
        // Foreign keys protect assigned accounts and retained invitation history.
        $id = DB::table('roles')->where('code', 'portfolio_editor')->sole()->id;
        DB::table('role_permissions')->where('role_id', $id)->delete();
        DB::table('roles')->where('id', $id)->delete();
        $this->invitationLimit(7);
    }

    private function invitationLimit(int $maximum): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION identity_staff_invitation_complete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS(SELECT 1 FROM identity_staff_invitations i WHERE i.id=NEW.id AND i.roles_sealed
                    AND (SELECT count(*) FROM identity_staff_invitation_roles r WHERE r.invitation_id=i.id) BETWEEN 1 AND {$maximum}) THEN
                    RAISE EXCEPTION 'Invitation roles must be complete at commit.' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END $$;
            SQL);
    }
};
