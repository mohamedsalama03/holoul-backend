<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectIntake;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class IntakeConstraintsTest extends TestCase
{
    use DatabaseMigrations;
    use IntakeFixtures;

    public function test_database_rejects_cross_category_selection_in_drafts_and_revisions(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $otherTaxonomy = $this->intakeTaxonomy();
        $draft = DB::table('request_drafts')->where('request_id', $record->id)->sole();

        $this->assertSqlState('23503', fn () => DB::table('request_drafts')->where('id', $draft->id)->update(['subcategory_id' => $otherTaxonomy['subcategory_id']]));
        $this->assertSqlState('23503', fn () => DB::table('request_revisions')->insert($this->revisionValues($record, ['subcategory_id' => $otherTaxonomy['subcategory_id']])));
        $this->assertSqlState('23514', fn () => DB::table('request_drafts')->where('id', $draft->id)->update(['category_id' => null]));
        $this->assertEquals($draft, DB::table('request_drafts')->where('id', $draft->id)->sole());
        $this->assertDatabaseCount('request_revisions', 1);
    }

    public function test_database_enforces_draft_budget_shapes_including_sql_null_semantics(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $draftId = DB::table('request_drafts')->where('request_id', $record->id)->value('id');
        foreach ([
            ['budget_unknown' => null, 'budget_minor' => null, 'currency' => null],
            ['budget_unknown' => true, 'budget_minor' => null, 'currency' => null],
            ['budget_unknown' => false, 'budget_minor' => null, 'currency' => null],
            ['budget_unknown' => false, 'budget_minor' => 0, 'currency' => 'USD'],
            ['budget_unknown' => false, 'budget_minor' => 0, 'currency' => 'LYD'],
        ] as $valid) {
            DB::table('request_drafts')->where('id', $draftId)->update($valid);
            $this->assertDatabaseHas('request_drafts', ['id' => $draftId, ...$valid]);
        }
        foreach ([
            ['budget_unknown' => null, 'budget_minor' => 0, 'currency' => 'USD'],
            ['budget_unknown' => null, 'budget_minor' => null, 'currency' => 'USD'],
            ['budget_unknown' => true, 'budget_minor' => 0, 'currency' => null],
            ['budget_unknown' => true, 'budget_minor' => 0, 'currency' => 'USD'],
            ['budget_unknown' => true, 'budget_minor' => null, 'currency' => 'USD'],
            ['budget_unknown' => false, 'budget_minor' => null, 'currency' => 'USD'],
            ['budget_unknown' => false, 'budget_minor' => 0, 'currency' => null],
            ['budget_unknown' => false, 'budget_minor' => -1, 'currency' => 'LYD'],
        ] as $invalid) {
            $this->assertSqlState('23514', fn () => DB::table('request_drafts')->where('id', $draftId)->update($invalid));
        }
        $this->assertSqlState('23503', fn () => DB::table('request_drafts')->where('id', $draftId)->update(['currency' => 'EUR']));
        $this->assertSqlState('22P02', fn () => DB::table('request_drafts')->where('id', $draftId)->update(['budget_minor' => '0.1']));
        $this->assertDatabaseHas('request_drafts', ['id' => $draftId, 'budget_unknown' => false, 'budget_minor' => 0, 'currency' => 'LYD']);
    }

    public function test_database_requires_complete_revision_budgets_and_preserves_exact_minor_units(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        foreach ([
            ['budget_unknown' => true, 'budget_minor' => 0, 'currency' => 'USD'],
            ['budget_unknown' => true, 'budget_minor' => null, 'currency' => 'USD'],
            ['budget_unknown' => true, 'budget_minor' => 0, 'currency' => null],
            ['budget_unknown' => false, 'budget_minor' => null, 'currency' => null],
            ['budget_unknown' => false, 'budget_minor' => 0, 'currency' => null],
            ['budget_unknown' => false, 'budget_minor' => null, 'currency' => 'LYD'],
            ['budget_unknown' => false, 'budget_minor' => -1, 'currency' => 'LYD'],
        ] as $invalid) {
            $this->assertSqlState('23514', fn () => DB::table('request_revisions')->insert($this->revisionValues($record, $invalid)));
        }
        $this->assertSqlState('23502', fn () => DB::table('request_revisions')->insert($this->revisionValues($record, ['budget_unknown' => null])));
        $this->assertSqlState('23503', fn () => DB::table('request_revisions')->insert($this->revisionValues($record, ['currency' => 'EUR'])));
        foreach ([
            ['budget_unknown' => true, 'budget_minor' => null, 'currency' => null],
            ['budget_unknown' => false, 'budget_minor' => 0, 'currency' => 'USD'],
            ['budget_unknown' => false, 'budget_minor' => 0, 'currency' => 'LYD'],
            ['budget_unknown' => false, 'budget_minor' => 1234567, 'currency' => 'LYD'],
        ] as $offset => $valid) {
            $row = $this->revisionValues($record, [...$valid, 'revision_number' => $offset + 2]);
            DB::table('request_revisions')->insert($row);
            $this->assertDatabaseHas('request_revisions', ['id' => $row['id'], ...$valid]);
        }
    }

    public function test_database_enforces_request_and_draft_ownership_and_one_draft_per_request(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $other = $this->createSubmitted($this->intakeCustomer());
        $this->assertSqlState('23503', fn () => DB::table('project_requests')->insert([
            'id' => (string) Str::uuid7(), 'customer_id' => $record->customer_id, 'customer_user_id' => $other->customer_user_id,
        ]));
        $this->assertSqlState('23514', fn () => DB::table('project_requests')->where('id', $record->id)->update([
            'customer_id' => $other->customer_id, 'customer_user_id' => $other->customer_user_id,
        ]));
        $bareRequestId = $this->bareRequest($record);
        $this->assertSqlState('23503', fn () => DB::table('request_drafts')->insert([
            'id' => (string) Str::uuid7(), 'request_id' => $bareRequestId, 'customer_id' => $other->customer_id,
        ]));
        $draft = DB::table('request_drafts')->where('request_id', $record->id)->sole();
        $this->assertSqlState('23514', fn () => DB::table('request_drafts')->where('id', $draft->id)->update(['customer_id' => $other->customer_id]));
        $this->assertSqlState('23514', fn () => DB::table('request_drafts')->where('id', $draft->id)->update(['request_id' => $bareRequestId]));
        $this->assertSqlState('23505', fn () => DB::table('request_drafts')->insert([
            'id' => (string) Str::uuid7(), 'request_id' => $record->id, 'customer_id' => $record->customer_id,
        ]));
    }

    public function test_database_ties_revision_owner_author_and_latest_number_to_the_same_request(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $other = $this->createSubmitted($this->intakeCustomer());
        foreach ([['customer_id' => $other->customer_id], ['submitted_by' => $other->customer_user_id], ['request_id' => $other->id]] as $invalid) {
            $this->assertSqlState('23503', fn () => DB::table('request_revisions')->insert($this->revisionValues($record, $invalid)));
        }
        $this->assertSqlState('23505', fn () => DB::table('request_revisions')->insert($this->revisionValues($record, ['revision_number' => 1])));
        $this->assertSqlState('23514', fn () => DB::table('request_revisions')->insert($this->revisionValues($record, ['revision_number' => 0])));
        $this->assertSqlState('23503', fn () => DB::table('project_requests')->where('id', $record->id)->update(['latest_revision_id' => $other->latest_revision_id]));
        $this->assertSqlState('23503', fn () => DB::table('project_requests')->where('id', $record->id)->update(['latest_revision_number' => 2]));
        $this->assertDatabaseHas('project_requests', ['id' => $record->id, 'latest_revision_id' => $record->latest_revision_id, 'latest_revision_number' => 1]);
    }

    public function test_database_keeps_references_unique_and_first_submission_identity_immutable(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $this->assertSqlState('23514', fn () => DB::table('project_requests')->where('id', $record->id)->update(['reference' => 'REQ-2026-99999']));
        $this->assertSqlState('23514', fn () => DB::table('project_requests')->where('id', $record->id)->update(['submitted_at' => now()->addDay()]));
        $bareRequestId = $this->bareRequest($record);
        $revision = $this->revisionValues($record, ['request_id' => $bareRequestId, 'revision_number' => 1, 'provenance' => 'customer_submission']);
        DB::table('request_revisions')->insert($revision);
        $this->assertSqlState('23505', fn () => DB::table('project_requests')->where('id', $bareRequestId)->update([
            'state' => 'submitted', 'reference' => $record->reference, 'submitted_at' => now(),
            'latest_revision_number' => 1, 'latest_revision_id' => $revision['id'],
        ]));
        $this->assertSqlState('23514', fn () => DB::table('project_requests')->where('id', $bareRequestId)->update(['state' => 'submitted']));
        $this->assertSqlState('23514', fn () => DB::table('project_requests')->where('id', $record->id)->update(['state' => 'approved']));
        $this->assertSqlState('23514', fn () => DB::table('project_requests')->where('id', $record->id)->update(['lock_version' => 0]));
    }

    public function test_database_limits_assignment_targets_and_actors_to_staff(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $staff = $this->intakeStaff();
        $this->assertSqlState('23503', fn () => DB::table('project_requests')->where('id', $record->id)->update(['assigned_staff_id' => $record->customer_user_id]));
        $base = ['request_id' => $record->id, 'previous_staff_id' => null, 'assigned_staff_id' => $staff->id, 'assigned_by' => $staff->id];
        foreach ([
            ['previous_staff_id' => $record->customer_user_id], ['assigned_staff_id' => $record->customer_user_id],
            ['assigned_by' => $record->customer_user_id], ['request_id' => (string) Str::uuid7()],
        ] as $invalid) {
            $this->assertSqlState('23503', fn () => DB::table('request_assignments')->insert(['id' => (string) Str::uuid7(), ...$base, ...$invalid]));
        }
        DB::table('request_assignments')->insert(['id' => (string) Str::uuid7(), ...$base]);
        $this->assertDatabaseCount('request_assignments', 1);
    }

    public function test_database_binds_information_question_response_and_resolution_to_the_same_owner_and_request(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $other = $this->createSubmitted($this->intakeCustomer());
        $staff = $this->intakeStaff();
        $question = ['request_id' => $record->id, 'customer_id' => $record->customer_id,
            'origin_state' => 'under_review', 'question' => 'Which users need access?', 'requested_by' => $staff->id];
        $this->assertSqlState('23503', fn () => DB::table('information_requests')->insert(['id' => (string) Str::uuid7(), ...$question, 'customer_id' => $other->customer_id]));
        $this->assertSqlState('23503', fn () => DB::table('information_requests')->insert(['id' => (string) Str::uuid7(), ...$question, 'requested_by' => $record->customer_user_id]));
        // B5 permits returning to Discovery after an information response; Proposal is not a valid origin.
        $this->assertSqlState('23514', fn () => DB::table('information_requests')->insert(['id' => (string) Str::uuid7(), ...$question, 'origin_state' => 'proposal']));
        $questionId = (string) Str::uuid7();
        DB::table('information_requests')->insert(['id' => $questionId, ...$question]);
        $this->assertSqlState('23503', fn () => DB::table('project_requests')->where('id', $other->id)->update(['state' => 'information_required', 'information_request_id' => $questionId]));
        $this->assertSqlState('23514', fn () => DB::table('project_requests')->where('id', $record->id)->update(['state' => 'information_required']));
        $this->assertSqlState('23514', fn () => DB::table('project_requests')->where('id', $record->id)->update(['information_request_id' => $questionId]));
        $response = ['information_request_id' => $questionId, 'request_id' => $record->id, 'customer_id' => $record->customer_id,
            'response' => 'Customers and administrators.', 'responded_by' => $record->customer_user_id];
        foreach ([['request_id' => $other->id], ['customer_id' => $other->customer_id], ['responded_by' => $other->customer_user_id]] as $invalid) {
            $this->assertSqlState('23503', fn () => DB::table('information_responses')->insert(['id' => (string) Str::uuid7(), ...$response, ...$invalid]));
        }
        DB::table('information_responses')->insert(['id' => (string) Str::uuid7(), ...$response]);
        $this->assertSqlState('23505', fn () => DB::table('information_responses')->insert(['id' => (string) Str::uuid7(), ...$response]));
        $resolution = ['information_request_id' => $questionId, 'request_id' => $record->id, 'resolution' => 'acknowledged', 'resolved_by' => $staff->id];
        $this->assertSqlState('23503', fn () => DB::table('information_resolutions')->insert(['id' => (string) Str::uuid7(), ...$resolution, 'request_id' => $other->id]));
        $this->assertSqlState('23514', fn () => DB::table('information_resolutions')->insert(['id' => (string) Str::uuid7(), ...$resolution, 'resolution' => 'discovery']));
        DB::table('information_resolutions')->insert(['id' => (string) Str::uuid7(), ...$resolution]);
        $this->assertSqlState('23505', fn () => DB::table('information_resolutions')->insert(['id' => (string) Str::uuid7(), ...$resolution]));
    }

    public function test_database_requires_real_state_change_history_and_rejection_reason(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $staff = $this->intakeStaff();
        $base = ['request_id' => $record->id, 'from_state' => 'under_review', 'to_state' => 'rejected', 'actor_id' => $staff->id, 'reason' => 'Outside supported scope.'];
        foreach ([['reason' => null], ['reason' => '  '], ['to_state' => 'under_review'], ['to_state' => 'approved'], ['from_state' => 'approved']] as $invalid) {
            $this->assertSqlState('23514', fn () => DB::table('request_state_changes')->insert(['id' => (string) Str::uuid7(), ...$base, ...$invalid]));
        }
        $this->assertSqlState('23503', fn () => DB::table('request_state_changes')->insert(['id' => (string) Str::uuid7(), ...$base, 'actor_id' => (string) Str::uuid7()]));
        DB::table('request_state_changes')->insert(['id' => (string) Str::uuid7(), ...$base]);
        $this->assertDatabaseHas('request_state_changes', $base);
    }

    public function test_database_binds_idempotency_claims_to_owner_request_and_revision(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $other = $this->createSubmitted($this->intakeCustomer());
        $base = ['actor_id' => $record->customer_user_id, 'operation' => 'intake.submit',
            'key_hash' => hash('sha256', 'a-new-raw-key'), 'input_hash' => hash('sha256', 'raw-canonical-input'),
            'request_id' => $record->id, 'revision_id' => $record->latest_revision_id, 'revision_number' => 1,
            'result_version' => $record->lock_version, 'result_state' => 'submitted', 'reference' => $record->reference,
            'expires_at' => now()->addHours(72)];
        $this->assertSqlState('23503', fn () => DB::table('intake_submission_keys')->insert(['id' => (string) Str::uuid7(), ...$base, 'actor_id' => $other->customer_user_id]));
        $this->assertSqlState('23503', fn () => DB::table('intake_submission_keys')->insert(['id' => (string) Str::uuid7(), ...$base, 'revision_id' => $other->latest_revision_id]));
        $this->assertSqlState('23514', fn () => DB::table('intake_submission_keys')->insert(['id' => (string) Str::uuid7(), ...$base, 'operation' => 'intake.forged']));
        $this->assertSqlState('23514', fn () => DB::table('intake_submission_keys')->insert(['id' => (string) Str::uuid7(), ...$base, 'key_hash' => 'not-a-hash']));
        DB::table('intake_submission_keys')->insert(['id' => (string) Str::uuid7(), ...$base]);
        $this->assertSqlState('23505', fn () => DB::table('intake_submission_keys')->insert(['id' => (string) Str::uuid7(), ...$base]));
    }

    public function test_database_deduplicates_passive_notification_intents_and_ties_them_to_revision(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $other = $this->createSubmitted($this->intakeCustomer());
        $base = ['request_id' => $record->id, 'revision_id' => $record->latest_revision_id, 'kind' => 'intake.submitted'];
        $this->assertSqlState('23505', fn () => DB::table('intake_notification_intents')->insert(['id' => (string) Str::uuid7(), ...$base]));
        $this->assertSqlState('23503', fn () => DB::table('intake_notification_intents')->insert(['id' => (string) Str::uuid7(), ...$base, 'request_id' => $other->id, 'kind' => 'intake.amended']));
        $this->assertSqlState('23514', fn () => DB::table('intake_notification_intents')->insert(['id' => (string) Str::uuid7(), ...$base, 'kind' => 'mail.sent']));
        $this->assertSame(1, DB::table('intake_notification_intents')->where('request_id', $record->id)->count());
    }

    public function test_original_contact_and_taxonomy_snapshot_survives_live_record_edits(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $original = DB::table('request_revisions')->where('id', $record->latest_revision_id)->sole();
        DB::table('users')->where('id', $record->customer_user_id)->update(['full_name' => 'Changed Contact', 'email' => 'changed@example.test']);
        DB::table('customers')->where('id', $record->customer_id)->update(['phone_e164' => '+12025550123', 'phone_display' => '+1 202 555 0123']);
        DB::table('categories')->where('id', $original->category_id)->update(['name' => 'Changed Category', 'active' => false, 'lock_version' => DB::raw('lock_version + 1')]);
        DB::table('subcategories')->where('id', $original->subcategory_id)->update(['name' => 'Changed Subcategory', 'active' => false, 'lock_version' => DB::raw('lock_version + 1')]);
        $this->assertEquals($original, DB::table('request_revisions')->where('id', $record->latest_revision_id)->sole());
        $auditMetadata = json_encode(DB::table('audit_events')->where('subject_id', $record->id)->pluck('metadata')->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach ([$original->full_name, $original->email, $original->phone_e164, $original->project_description] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $auditMetadata);
        }
    }

    /** @return array<string, array{string}> */
    public static function immutableTables(): array
    {
        return [
            'submitted revisions' => ['request_revisions'], 'assignment history' => ['request_assignments'],
            'information questions' => ['information_requests'], 'information responses' => ['information_responses'],
            'information resolutions' => ['information_resolutions'], 'state history' => ['request_state_changes'],
            'passive notification intent' => ['intake_notification_intents'],
        ];
    }

    #[DataProvider('immutableTables')]
    public function test_history_is_append_only_even_for_raw_sql_and_keeps_runtime_privileges_restricted(string $table): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $ids = $this->historyRows($record);
        $id = $ids[$table];
        $before = DB::table($table)->where('id', $id)->sole();
        $this->assertSqlState('55000', fn () => DB::table($table)->where('id', $id)->update(['id' => $id]));
        $this->assertSqlState('55000', fn () => DB::table($table)->where('id', $id)->delete());
        // CASCADE reaches the trigger instead of stopping first on referencing FKs.
        $this->assertSqlState('55000', fn () => DB::statement('TRUNCATE TABLE '.$table.' CASCADE'));
        $this->assertEquals($before, DB::table($table)->where('id', $id)->sole());

        DB::statement('SET ROLE holoul_app');
        try {
            $this->assertTrue(DB::scalar("SELECT has_table_privilege(current_user, ?, 'SELECT') AND has_table_privilege(current_user, ?, 'INSERT')", [$table, $table]));
            $this->assertFalse(DB::scalar("SELECT has_table_privilege(current_user, ?, 'UPDATE,DELETE,TRUNCATE')", [$table]));
            $this->assertEquals($before, DB::table($table)->where('id', $id)->sole());
            $this->assertSqlState('42501', fn () => DB::table($table)->where('id', $id)->update(['id' => $id]));
            $this->assertSqlState('42501', fn () => DB::table($table)->where('id', $id)->delete());
            $this->assertSqlState('42501', fn () => DB::statement('TRUNCATE TABLE '.$table.' CASCADE'));
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    public function test_failed_submission_audit_rolls_back_revision_claim_transition_and_notification_intent(): void
    {
        $actor = $this->intakeActor($this->intakeCustomer());
        $record = app(ManageDraft::class)->create($actor, $this->intakeInput(), (string) Str::uuid7());
        $etag = VersionPrecondition::etag($record->id, $record->lock_version);
        $key = (string) Str::uuid7();
        $tables = ['project_requests', 'request_drafts', 'request_revisions', 'request_state_changes', 'intake_submission_keys', 'intake_notification_intents', 'audit_events'];
        $before = $this->snapshot($tables);
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT intake_atomicity_reject_audit CHECK (event_type <> 'intake.submitted')");
        try {
            $this->assertSqlState('23514', fn () => app(SubmitRequest::class)->handle($actor, $record->id, $etag, $key, (string) Str::uuid7()));
            $this->assertSame($before, $this->snapshot($tables));
            $this->assertDatabaseHas('project_requests', ['id' => $record->id, 'state' => 'draft', 'reference' => null, 'latest_revision_number' => 0]);
        } finally {
            DB::statement('ALTER TABLE audit_events DROP CONSTRAINT intake_atomicity_reject_audit');
        }
        // A failed attempt did not consume the key or draft version. Sequence gaps are allowed.
        $result = app(SubmitRequest::class)->handle($actor, $record->id, $etag, $key, (string) Str::uuid7());
        $this->assertSame(1, $result->revision_number);
        $this->assertDatabaseCount('request_revisions', 1);
        $this->assertDatabaseCount('intake_submission_keys', 1);
        $this->assertDatabaseCount('intake_notification_intents', 1);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function revisionValues(ProjectRequest $record, array $overrides = []): array
    {
        $original = (array) DB::table('request_revisions')->where('id', $record->latest_revision_id)->sole();

        return [...$original, 'id' => (string) Str::uuid7(), 'revision_number' => 2, 'provenance' => 'customer_amendment', ...$overrides];
    }

    private function bareRequest(ProjectRequest $record): string
    {
        $id = (string) Str::uuid7();
        DB::table('project_requests')->insert(['id' => $id, 'customer_id' => $record->customer_id, 'customer_user_id' => $record->customer_user_id]);

        return $id;
    }

    /** @return array<string, string> */
    private function historyRows(ProjectRequest $record): array
    {
        $staff = $this->intakeStaff();
        $assignment = (string) Str::uuid7();
        $question = (string) Str::uuid7();
        $response = (string) Str::uuid7();
        $resolution = (string) Str::uuid7();
        DB::table('request_assignments')->insert(['id' => $assignment, 'request_id' => $record->id, 'assigned_staff_id' => $staff->id, 'assigned_by' => $staff->id]);
        DB::table('information_requests')->insert(['id' => $question, 'request_id' => $record->id, 'customer_id' => $record->customer_id,
            'origin_state' => 'under_review', 'question' => 'Clarify the user groups.', 'requested_by' => $staff->id]);
        DB::table('information_responses')->insert(['id' => $response, 'information_request_id' => $question,
            'request_id' => $record->id, 'customer_id' => $record->customer_id, 'response' => 'Customer and staff users.', 'responded_by' => $record->customer_user_id]);
        DB::table('information_resolutions')->insert(['id' => $resolution, 'information_request_id' => $question,
            'request_id' => $record->id, 'resolution' => 'acknowledged', 'resolved_by' => $staff->id]);

        return ['request_revisions' => $record->latest_revision_id, 'request_assignments' => $assignment,
            'information_requests' => $question, 'information_responses' => $response, 'information_resolutions' => $resolution,
            'request_state_changes' => DB::table('request_state_changes')->where('request_id', $record->id)->sole()->id,
            'intake_notification_intents' => DB::table('intake_notification_intents')->where('request_id', $record->id)->sole()->id];
    }

    /** @param list<string> $tables
     * @return array<string, list<string>>
     */
    private function snapshot(array $tables): array
    {
        $snapshots = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(fn (object $row): string => json_encode((array) $row, JSON_THROW_ON_ERROR))->all();
            sort($rows);
            $snapshots[$table] = $rows;
        }

        return $snapshots;
    }

    /** @param callable(): mixed $statement */
    private function assertSqlState(string $expected, callable $statement): void
    {
        try {
            $statement();
            $this->fail('PostgreSQL accepted an invalid intake record or history mutation.');
        } catch (QueryException $exception) {
            $this->assertSame($expected, $exception->getCode());
        }
    }
}
