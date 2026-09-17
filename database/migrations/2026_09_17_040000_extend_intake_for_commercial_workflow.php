<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE project_requests DROP CONSTRAINT project_requests_state_check;
            ALTER TABLE project_requests ADD CONSTRAINT project_requests_state_check CHECK
                (state IN ('draft','submitted','under_review','information_required','discovery','proposal','approved','rejected','withdrawn'));
            ALTER TABLE request_state_changes DROP CONSTRAINT request_state_changes_from_state_check;
            ALTER TABLE request_state_changes DROP CONSTRAINT request_state_changes_to_state_check;
            ALTER TABLE request_state_changes ADD CONSTRAINT request_state_changes_from_state_check CHECK
                (from_state IN ('draft','submitted','under_review','information_required','discovery','proposal','approved'));
            ALTER TABLE request_state_changes ADD CONSTRAINT request_state_changes_to_state_check CHECK
                (to_state IN ('submitted','under_review','information_required','discovery','proposal','approved','rejected','withdrawn'));
            ALTER TABLE information_requests DROP CONSTRAINT information_requests_origin_state_check;
            ALTER TABLE information_requests ADD CONSTRAINT information_requests_origin_state_check CHECK (origin_state IN ('under_review','discovery'));
            ALTER TABLE request_state_changes ADD COLUMN entity_version bigint CHECK (entity_version > 0);
            ALTER TABLE request_state_changes ADD COLUMN correlation_id uuid;
            ALTER TABLE request_state_changes ALTER COLUMN actor_id DROP NOT NULL;
            ALTER TABLE request_state_changes ADD CONSTRAINT commercial_history_context CHECK
                ((from_state NOT IN ('proposal','approved') AND to_state NOT IN ('proposal','approved'))
                    OR (entity_version IS NOT NULL AND correlation_id IS NOT NULL));
            ALTER TABLE request_state_changes ADD CONSTRAINT commercial_history_system_actor CHECK
                (actor_id IS NOT NULL OR (from_state='proposal' AND to_state='discovery'
                    AND entity_version IS NOT NULL AND correlation_id IS NOT NULL));
            SQL);
    }

    public function down(): void
    {
        // Refuse rollback once B5 states/history exist: never rewrite commercial history.
        DB::unprepared(<<<'SQL'
            ALTER TABLE request_state_changes DROP CONSTRAINT commercial_history_context;
            ALTER TABLE request_state_changes DROP CONSTRAINT commercial_history_system_actor;
            ALTER TABLE project_requests DROP CONSTRAINT project_requests_state_check;
            ALTER TABLE project_requests ADD CONSTRAINT project_requests_state_check CHECK
                (state IN ('draft','submitted','under_review','information_required','discovery','rejected','withdrawn'));
            ALTER TABLE request_state_changes DROP CONSTRAINT request_state_changes_from_state_check;
            ALTER TABLE request_state_changes DROP CONSTRAINT request_state_changes_to_state_check;
            ALTER TABLE request_state_changes ADD CONSTRAINT request_state_changes_from_state_check CHECK
                (from_state IN ('draft','submitted','under_review','information_required','discovery'));
            ALTER TABLE request_state_changes ADD CONSTRAINT request_state_changes_to_state_check CHECK
                (to_state IN ('submitted','under_review','information_required','discovery','rejected','withdrawn'));
            ALTER TABLE information_requests DROP CONSTRAINT information_requests_origin_state_check;
            ALTER TABLE information_requests ADD CONSTRAINT information_requests_origin_state_check CHECK (origin_state='under_review');
            ALTER TABLE request_state_changes DROP COLUMN entity_version, DROP COLUMN correlation_id;
            ALTER TABLE request_state_changes ALTER COLUMN actor_id SET NOT NULL;
            SQL);
    }
};
