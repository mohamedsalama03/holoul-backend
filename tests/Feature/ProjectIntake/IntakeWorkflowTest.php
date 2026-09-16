<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectIntake;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\AssignRequest;
use App\Modules\ProjectIntake\Actions\InformationWorkflow;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Actions\TransitionRequest;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\ProjectIntake\Models\RequestDraft;
use App\Modules\ProjectIntake\Models\RequestRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class IntakeWorkflowTest extends TestCase
{
    use DatabaseMigrations;
    use IntakeFixtures;

    public function test_incomplete_draft_can_be_completed_and_submitted_with_an_exact_snapshot(): void
    {
        $customer = $this->intakeCustomer();
        $actor = $this->intakeActor($customer);
        $drafts = app(ManageDraft::class);
        $record = $drafts->create($actor, [], $this->requestId());
        self::assertSame(RequestState::Draft, $record->state);
        self::assertNull($record->reference);
        self::assertTrue(RequestDraft::query()->where('request_id', $record->id)->sole()->is_open);
        $this->assertValidation(fn () => app(SubmitRequest::class)->handle($actor, $record->id, $this->etag($record), $this->key(), $this->requestId()));
        $this->assertDatabaseCount('intake_submission_keys', 0);
        $this->assertDatabaseCount('request_revisions', 0);
        $input = $this->intakeInput();
        $record = $drafts->update($actor, $record->id, $this->etag($record), $input, $this->requestId());
        $receipt = app(SubmitRequest::class)->handle($actor, $record->id, $this->etag($record), $this->key(), $this->requestId());
        $record->refresh();
        self::assertSame(RequestState::Submitted, $record->state);
        self::assertSame(1, $record->latest_revision_number);
        self::assertSame($record->latest_revision_id, $receipt->revision_id);
        self::assertMatchesRegularExpression('/\AREQ-[0-9]{4}-[0-9]{5,19}\z/', $record->reference ?? '');
        $revision = RequestRevision::query()->where('request_id', $record->id)->sole();
        self::assertSame($customer->full_name, $revision->full_name);
        self::assertSame($customer->email, $revision->email);
        self::assertSame('+218912345678', $revision->phone_e164);
        self::assertSame($input['project_description'], $revision->project_description);
        self::assertSame(1234567, $revision->budget_minor);
        self::assertSame('LYD', $revision->currency);
        self::assertSame('customer_submission', $revision->provenance);
        self::assertFalse(RequestDraft::query()->where('request_id', $record->id)->sole()->is_open);
        $this->assertDatabaseHas('intake_notification_intents', ['request_id' => $record->id, 'revision_id' => $revision->id, 'kind' => 'intake.submitted']);
        $audit = json_encode(DB::table('audit_events')->where('subject_id', $record->id)->get(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($customer->email, $audit);
        self::assertStringNotContainsString($revision->project_description, $audit);
    }

    public function test_unverified_account_cannot_submit_even_if_a_stale_actor_claims_verification(): void
    {
        $customer = $this->intakeCustomer(false);
        $actor = $this->intakeActor($customer);
        $record = app(ManageDraft::class)->create($actor, $this->intakeInput(), $this->requestId());
        $staleVerified = new IntakeActor($actor->id, $actor->customerId, true, $actor->permissions);
        foreach ([$actor, $staleVerified] as $candidate) {
            $this->assertForbidden(fn () => app(SubmitRequest::class)->handle($candidate, $record->id, $this->etag($record), $this->key(), $this->requestId()));
        }
        self::assertSame(RequestState::Draft, $record->refresh()->state);
        $this->assertDatabaseCount('request_revisions', 0);
        $this->assertDatabaseCount('intake_submission_keys', 0);
        $this->assertDatabaseCount('intake_notification_intents', 0);
    }

    public function test_submission_replay_returns_the_original_receipt_after_workflow_has_advanced(): void
    {
        $customer = $this->intakeCustomer();
        $actor = $this->intakeActor($customer);
        $record = app(ManageDraft::class)->create($actor, $this->intakeInput(), $this->requestId());
        $etag = $this->etag($record);
        $key = $this->key();
        $first = app(SubmitRequest::class)->handle($actor, $record->id, $etag, $key, $this->requestId());
        $staff = $this->intakeStaff();
        $staffActor = $this->intakeActor($staff);
        $record->refresh();
        $record = app(AssignRequest::class)->handle($staffActor, $record->id, $this->etag($record), $staff->id, $this->requestId());
        $record = app(TransitionRequest::class)->handle($staffActor, $record->id, $this->etag($record), 'review', null, $this->requestId());
        $replay = app(SubmitRequest::class)->handle($actor, $record->id, $etag, $key, $this->requestId());
        self::assertSame($first->getAttributes(), $replay->getAttributes());
        self::assertSame('submitted', $replay->result_state);
        self::assertSame(RequestState::UnderReview, $record->refresh()->state);
        self::assertLessThan($record->lock_version, $replay->result_version);
        $this->assertDatabaseCount('request_revisions', 1);
        $this->assertDatabaseCount('intake_notification_intents', 1);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'intake.submitted')->count());
    }

    public function test_reusing_submission_key_with_different_precondition_or_request_conflicts(): void
    {
        $customer = $this->intakeCustomer();
        $actor = $this->intakeActor($customer);
        $record = app(ManageDraft::class)->create($actor, $this->intakeInput(), $this->requestId());
        $key = $this->key();
        app(SubmitRequest::class)->handle($actor, $record->id, $this->etag($record), $key, $this->requestId());
        $record->refresh();
        $this->assertStatus(409, fn () => app(SubmitRequest::class)->handle($actor, $record->id, $this->etag($record), $key, $this->requestId()));
        $other = app(ManageDraft::class)->create($actor, $this->intakeInput(), $this->requestId());
        $this->assertStatus(409, fn () => app(SubmitRequest::class)->handle($actor, $other->id, $this->etag($other), $key, $this->requestId()));
        self::assertSame(RequestState::Draft, $other->refresh()->state);
        $this->assertDatabaseCount('request_revisions', 1);
        $this->assertDatabaseCount('intake_submission_keys', 1);
        $this->assertDatabaseCount('intake_notification_intents', 1);
    }

    public function test_audit_failure_rolls_back_submission_revision_reference_receipt_and_intent(): void
    {
        $customer = $this->intakeCustomer();
        $actor = $this->intakeActor($customer);
        $record = app(ManageDraft::class)->create($actor, $this->intakeInput(), $this->requestId());
        $etag = $this->etag($record);
        $key = $this->key();
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT intake_test_submission_audit CHECK (event_type <> 'intake.submitted')");
        try {
            try {
                app(SubmitRequest::class)->handle($actor, $record->id, $etag, $key, $this->requestId());
                self::fail('Submission committed without its audit.');
            } catch (QueryException $exception) {
                self::assertSame('23514', $exception->getCode());
            }
            self::assertSame(RequestState::Draft, $record->refresh()->state);
            self::assertNull($record->reference);
            self::assertSame(1, $record->lock_version);
            self::assertTrue(RequestDraft::query()->where('request_id', $record->id)->sole()->is_open);
            $this->assertDatabaseCount('request_revisions', 0);
            $this->assertDatabaseCount('request_state_changes', 0);
            $this->assertDatabaseCount('intake_submission_keys', 0);
            $this->assertDatabaseCount('intake_notification_intents', 0);
        } finally {
            DB::statement('ALTER TABLE audit_events DROP CONSTRAINT intake_test_submission_audit');
        }
        app(SubmitRequest::class)->handle($actor, $record->id, $etag, $key, $this->requestId());
        $this->assertDatabaseCount('request_revisions', 1);
    }

    public function test_amendment_preserves_revision_one_reference_and_first_submission_time(): void
    {
        $customer = $this->intakeCustomer();
        $actor = $this->intakeActor($customer);
        $record = $this->createSubmitted($customer);
        $original = DB::table('request_revisions')->where('request_id', $record->id)->sole();
        $reference = $record->reference;
        $submittedAt = $record->submitted_at;
        $this->assertStatus(409, fn () => app(ManageDraft::class)->update($actor, $record->id, $this->etag($record), ['project_name' => 'Unopened amendment'], $this->requestId()));
        DB::table('categories')->where('id', $original->category_id)->update(['name' => 'Renamed Category', 'lock_version' => DB::raw('lock_version + 1')]);
        DB::table('subcategories')->where('id', $original->subcategory_id)->update(['name' => 'Renamed Subcategory', 'lock_version' => DB::raw('lock_version + 1')]);
        DB::table('users')->where('id', $customer->id)->update(['full_name' => 'Updated Customer Contact']);
        DB::table('customers')->where('user_id', $customer->id)->update(['phone_e164' => '+218923456789']);
        $record = app(ManageDraft::class)->amend($actor, $record->id, $this->etag($record), $this->requestId());
        $this->assertStatus(409, fn () => app(ManageDraft::class)->amend($actor, $record->id, $this->etag($record), $this->requestId()));
        $record = app(ManageDraft::class)->update($actor, $record->id, $this->etag($record), ['project_name' => 'Amended Project', 'estimated_budget' => '0.00', 'currency' => 'USD'], $this->requestId());
        app(SubmitRequest::class)->handle($actor, $record->id, $this->etag($record), $this->key(), $this->requestId());
        $record->refresh();
        self::assertEquals($original, DB::table('request_revisions')->where('id', $original->id)->sole());
        self::assertSame(2, $record->latest_revision_number);
        self::assertSame($reference, $record->reference);
        self::assertEquals($submittedAt, $record->submitted_at);
        $amended = RequestRevision::query()->where('request_id', $record->id)->where('revision_number', 2)->sole();
        self::assertSame('customer_amendment', $amended->provenance);
        self::assertSame('Updated Customer Contact', $amended->full_name);
        self::assertSame('+218923456789', $amended->phone_e164);
        self::assertSame('Renamed Category', $amended->category_label);
        self::assertSame('Renamed Subcategory', $amended->subcategory_label);
        self::assertSame(0, $amended->budget_minor);
        self::assertSame('USD', $amended->currency);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'intake.amendment_submitted')->count());
        self::assertSame(1, DB::table('intake_notification_intents')->where('kind', 'intake.amended')->count());
    }

    public function test_review_discovery_and_customer_withdrawal_preserve_state_history(): void
    {
        [$customer, $staff, $record] = $this->reviewed();
        $staffActor = $this->intakeActor($staff);
        $record = app(TransitionRequest::class)->handle($staffActor, $record->id, $this->etag($record), 'discovery', null, $this->requestId());
        self::assertSame(RequestState::Discovery, $record->state);
        $record = app(TransitionRequest::class)->handle($this->intakeActor($customer), $record->id, $this->etag($record), 'withdraw', null, $this->requestId());
        self::assertSame(RequestState::Withdrawn, $record->state);
        self::assertSame(['submitted', 'under_review', 'discovery', 'withdrawn'], DB::table('request_state_changes')->where('request_id', $record->id)->orderBy('created_at')->orderBy('id')->pluck('to_state')->all());
        self::assertFalse(RequestDraft::query()->where('request_id', $record->id)->sole()->is_open);
        $this->assertStatus(409, fn () => app(ManageDraft::class)->amend($this->intakeActor($customer), $record->id, $this->etag($record), $this->requestId()));
    }

    public function test_rejection_requires_reason_and_terminal_requests_cannot_reopen(): void
    {
        [$customer, $staff, $record] = $this->reviewed();
        $staffActor = $this->intakeActor($staff);
        $this->assertValidation(fn () => app(TransitionRequest::class)->handle($staffActor, $record->id, $this->etag($record), 'reject', ' ', $this->requestId()));
        $record = app(TransitionRequest::class)->handle($staffActor, $record->id, $this->etag($record), 'reject', 'Outside the supported scope.', $this->requestId());
        self::assertSame(RequestState::Rejected, $record->state);
        $this->assertDatabaseHas('request_state_changes', ['request_id' => $record->id, 'from_state' => 'under_review', 'to_state' => 'rejected', 'reason' => 'Outside the supported scope.']);
        $this->assertStatus(409, fn () => app(TransitionRequest::class)->handle($staffActor, $record->id, $this->etag($record), 'review', null, $this->requestId()));
        $this->assertStatus(409, fn () => app(TransitionRequest::class)->handle($this->intakeActor($customer), $record->id, $this->etag($record), 'withdraw', null, $this->requestId()));
        $this->assertStatus(409, fn () => app(ManageDraft::class)->amend($this->intakeActor($customer), $record->id, $this->etag($record), $this->requestId()));
    }

    /** @return iterable<string,array{string}> */
    public static function prematureActions(): iterable
    {
        yield 'cannot skip review for discovery' => ['discovery'];
        yield 'cannot reject submitted without review' => ['reject'];
    }

    #[DataProvider('prematureActions')]
    public function test_submitted_request_cannot_skip_the_review_phase(string $action): void
    {
        $customer = $this->intakeCustomer();
        $staff = $this->intakeStaff();
        $staffActor = $this->intakeActor($staff);
        $record = $this->createSubmitted($customer);
        $record = app(AssignRequest::class)->handle($staffActor, $record->id, $this->etag($record), $staff->id, $this->requestId());
        $version = $record->lock_version;
        $this->assertStatus(409, fn () => app(TransitionRequest::class)->handle($staffActor, $record->id, $this->etag($record), $action, 'A reason', $this->requestId()));
        self::assertSame(RequestState::Submitted, $record->refresh()->state);
        self::assertSame($version, $record->lock_version);
        self::assertSame(1, DB::table('request_state_changes')->where('request_id', $record->id)->count());
    }

    public function test_reassignment_is_historical_and_revokes_the_previous_assignee_scope(): void
    {
        $customer = $this->intakeCustomer();
        $manager = $this->intakeStaff();
        $reviewer = $this->intakeStaff('reviewer');
        $managerActor = $this->intakeActor($manager);
        $record = $this->createSubmitted($customer);
        $record = app(AssignRequest::class)->handle($managerActor, $record->id, $this->etag($record), $manager->id, $this->requestId());
        $oldVersion = $this->etag($record);
        $record = app(AssignRequest::class)->handle($managerActor, $record->id, $oldVersion, $reviewer->id, $this->requestId());
        self::assertSame($reviewer->id, $record->assigned_staff_id);
        self::assertSame(2, DB::table('request_assignments')->where('request_id', $record->id)->count());
        $this->assertDatabaseHas('request_assignments', ['request_id' => $record->id, 'previous_staff_id' => $manager->id, 'assigned_staff_id' => $reviewer->id, 'assigned_by' => $manager->id]);
        $this->assertStatus(404, fn () => app(TransitionRequest::class)->handle($managerActor, $record->id, $this->etag($record), 'review', null, $this->requestId()));
        $record = app(TransitionRequest::class)->handle($this->intakeActor($reviewer), $record->id, $this->etag($record), 'review', null, $this->requestId());
        self::assertSame(RequestState::UnderReview, $record->state);
        $this->assertForbidden(fn () => app(AssignRequest::class)->handle($this->intakeActor($reviewer), $record->id, $this->etag($record), $manager->id, $this->requestId()));
        $this->assertForbidden(fn () => app(TransitionRequest::class)->handle($this->intakeActor($reviewer), $record->id, $this->etag($record), 'reject', 'Reviewer cannot reject.', $this->requestId()));
    }

    public function test_information_response_waits_for_acknowledgement_and_returns_only_to_stored_phase(): void
    {
        [$customer, $staff, $record] = $this->reviewed();
        $owner = $this->intakeActor($customer);
        $staffActor = $this->intakeActor($staff);
        $workflow = app(InformationWorkflow::class);
        $record = $workflow->ask($staffActor, $record->id, $this->etag($record), 'Which teams need access?', $this->requestId());
        $questionId = $record->information_request_id;
        self::assertNotNull($questionId);
        self::assertSame(RequestState::InformationRequired, $record->state);
        $this->assertDatabaseHas('information_requests', ['id' => $questionId, 'request_id' => $record->id, 'origin_state' => 'under_review', 'question' => 'Which teams need access?', 'requested_by' => $staff->id]);
        $this->assertStatus(409, fn () => $workflow->acknowledge($staffActor, $record->id, $questionId, $this->etag($record), $this->requestId()));
        $this->assertStatus(409, fn () => $workflow->ask($staffActor, $record->id, $this->etag($record), 'A second unresolved question', $this->requestId()));
        $this->assertStatus(409, fn () => app(TransitionRequest::class)->handle($staffActor, $record->id, $this->etag($record), 'discovery', null, $this->requestId()));
        $record = $workflow->respond($owner, $record->id, $questionId, $this->etag($record), 'The operations and review teams.', $this->requestId());
        self::assertSame(RequestState::InformationRequired, $record->state);
        self::assertSame($questionId, $record->information_request_id);
        $this->assertStatus(409, fn () => $workflow->respond($owner, $record->id, $questionId, $this->etag($record), 'Duplicate answer', $this->requestId()));
        $record = $workflow->acknowledge($staffActor, $record->id, $questionId, $this->etag($record), $this->requestId());
        self::assertSame(RequestState::UnderReview, $record->state);
        self::assertNull($record->information_request_id);
        $this->assertDatabaseHas('information_responses', ['information_request_id' => $questionId, 'responded_by' => $customer->id, 'response' => 'The operations and review teams.']);
        $this->assertDatabaseHas('information_resolutions', ['information_request_id' => $questionId, 'resolution' => 'acknowledged', 'resolved_by' => $staff->id]);
        $this->assertStatus(409, fn () => $workflow->acknowledge($staffActor, $record->id, $questionId, $this->etag($record), $this->requestId()));
        $this->assertDatabaseCount('information_requests', 1);
        $this->assertDatabaseCount('information_responses', 1);
        $this->assertDatabaseCount('information_resolutions', 1);
    }

    public function test_withdrawal_closes_open_information_without_erasing_question_or_response(): void
    {
        [$customer, $staff, $record] = $this->reviewed();
        $owner = $this->intakeActor($customer);
        $staffActor = $this->intakeActor($staff);
        $record = app(InformationWorkflow::class)->ask($staffActor, $record->id, $this->etag($record), 'Clarify the delivery needs.', $this->requestId());
        $questionId = $record->information_request_id;
        self::assertNotNull($questionId);
        $record = app(InformationWorkflow::class)->respond($owner, $record->id, $questionId, $this->etag($record), 'Delivery is no longer needed.', $this->requestId());
        $record = app(TransitionRequest::class)->handle($owner, $record->id, $this->etag($record), 'withdraw', null, $this->requestId());
        self::assertSame(RequestState::Withdrawn, $record->state);
        self::assertNull($record->information_request_id);
        $this->assertDatabaseHas('information_resolutions', ['information_request_id' => $questionId, 'resolution' => 'closed']);
        $this->assertDatabaseCount('information_requests', 1);
        $this->assertDatabaseCount('information_responses', 1);
        $this->assertStatus(409, fn () => app(InformationWorkflow::class)->acknowledge($staffActor, $record->id, $questionId, $this->etag($record), $this->requestId()));
    }

    public function test_b0_manager_rejection_from_information_required_closes_and_preserves_the_question(): void
    {
        [$customer, $staff, $record] = $this->reviewed();
        $staffActor = $this->intakeActor($staff);
        $record = app(InformationWorkflow::class)->ask($staffActor, $record->id, $this->etag($record), 'Clarify the requested integration.', $this->requestId());
        $questionId = $record->information_request_id;
        self::assertNotNull($questionId);
        // B0 explicitly permits the PM to reject this phase with a reason.
        $record = app(TransitionRequest::class)->handle($staffActor, $record->id, $this->etag($record), 'reject', 'The integration cannot be supported.', $this->requestId());
        self::assertSame(RequestState::Rejected, $record->state);
        self::assertNull($record->information_request_id);
        $this->assertDatabaseHas('information_resolutions', ['information_request_id' => $questionId, 'resolution' => 'closed']);
        $this->assertDatabaseHas('information_requests', ['id' => $questionId, 'origin_state' => 'under_review', 'question' => 'Clarify the requested integration.']);
        $this->assertDatabaseHas('request_state_changes', ['request_id' => $record->id, 'from_state' => 'information_required', 'to_state' => 'rejected', 'reason' => 'The integration cannot be supported.']);
        $this->assertStatus(409, fn () => app(InformationWorkflow::class)->respond($this->intakeActor($customer), $record->id, $questionId, $this->etag($record), 'A late response', $this->requestId()));
    }

    public function test_information_audit_failure_rolls_back_question_phase_history_and_version(): void
    {
        [, $staff, $record] = $this->reviewed();
        $version = $record->lock_version;
        $historyCount = DB::table('request_state_changes')->where('request_id', $record->id)->count();
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT intake_test_information_audit CHECK (event_type <> 'intake.information_requested')");
        try {
            try {
                app(InformationWorkflow::class)->ask($this->intakeActor($staff), $record->id, $this->etag($record), 'Must roll back.', $this->requestId());
                self::fail('Information request committed without its audit.');
            } catch (QueryException $exception) {
                self::assertSame('23514', $exception->getCode());
            }
            self::assertSame(RequestState::UnderReview, $record->refresh()->state);
            self::assertSame($version, $record->lock_version);
            self::assertNull($record->information_request_id);
            self::assertSame($historyCount, DB::table('request_state_changes')->where('request_id', $record->id)->count());
            $this->assertDatabaseCount('information_requests', 0);
        } finally {
            DB::statement('ALTER TABLE audit_events DROP CONSTRAINT intake_test_information_audit');
        }
    }

    public function test_unknown_budget_submission_keeps_amount_and_currency_absent(): void
    {
        $customer = $this->intakeCustomer();
        $actor = $this->intakeActor($customer);
        $record = app(ManageDraft::class)->create($actor, [...$this->intakeInput(), 'budget_unknown' => true, 'estimated_budget' => null, 'currency' => null], $this->requestId());
        app(SubmitRequest::class)->handle($actor, $record->id, $this->etag($record), $this->key(), $this->requestId());
        $revision = RequestRevision::query()->where('request_id', $record->id)->sole();
        self::assertTrue($revision->budget_unknown);
        self::assertNull($revision->budget_minor);
        self::assertNull($revision->currency);
    }

    public function test_stale_and_missing_versions_reject_draft_assignment_transition_and_information_mutations(): void
    {
        $customer = $this->intakeCustomer();
        $owner = $this->intakeActor($customer);
        $record = app(ManageDraft::class)->create($owner, $this->intakeInput(), $this->requestId());
        $stale = $this->etag($record);
        $record = app(ManageDraft::class)->update($owner, $record->id, $stale, ['project_name' => 'Updated once'], $this->requestId());
        $this->assertStatus(412, fn () => app(ManageDraft::class)->update($owner, $record->id, $stale, ['project_name' => 'Stale overwrite'], $this->requestId()));
        $this->assertStatus(428, fn () => app(ManageDraft::class)->update($owner, $record->id, null, ['project_name' => 'Unconditional overwrite'], $this->requestId()));
        app(SubmitRequest::class)->handle($owner, $record->id, $this->etag($record), $this->key(), $this->requestId());
        $record->refresh();
        $staff = $this->intakeStaff();
        $staffActor = $this->intakeActor($staff);
        $stale = $this->etag($record);
        $record = app(AssignRequest::class)->handle($staffActor, $record->id, $stale, $staff->id, $this->requestId());
        $this->assertStatus(412, fn () => app(AssignRequest::class)->handle($staffActor, $record->id, $stale, $staff->id, $this->requestId()));
        $this->assertStatus(412, fn () => app(TransitionRequest::class)->handle($staffActor, $record->id, $stale, 'review', null, $this->requestId()));
        $record = app(TransitionRequest::class)->handle($staffActor, $record->id, $this->etag($record), 'review', null, $this->requestId());
        $stale = $this->etag($record);
        $record = app(InformationWorkflow::class)->ask($staffActor, $record->id, $stale, 'Please clarify.', $this->requestId());
        $questionId = $record->information_request_id;
        self::assertNotNull($questionId);
        $this->assertStatus(412, fn () => app(InformationWorkflow::class)->respond($owner, $record->id, $questionId, $stale, 'Stale answer', $this->requestId()));
        $this->assertDatabaseCount('information_responses', 0);
        self::assertSame('Updated once', RequestDraft::query()->where('request_id', $record->id)->sole()->project_name);
    }

    /** @return array{User,User,ProjectRequest} */
    private function reviewed(): array
    {
        $customer = $this->intakeCustomer();
        $staff = $this->intakeStaff();
        $actor = $this->intakeActor($staff);
        $record = $this->createSubmitted($customer);
        $record = app(AssignRequest::class)->handle($actor, $record->id, $this->etag($record), $staff->id, $this->requestId());
        $record = app(TransitionRequest::class)->handle($actor, $record->id, $this->etag($record), 'review', null, $this->requestId());

        return [$customer, $staff, $record];
    }

    private function etag(ProjectRequest $record): string
    {
        return VersionPrecondition::etag($record->id, $record->lock_version);
    }

    private function key(): string
    {
        return (string) Str::uuid7();
    }

    private function requestId(): string
    {
        return (string) Str::uuid7();
    }

    /** @param callable(): mixed $action */
    private function assertStatus(int $status, callable $action): void
    {
        try {
            $action();
            self::fail('A disallowed intake action succeeded.');
        } catch (HttpExceptionInterface $exception) {
            self::assertSame($status, $exception->getStatusCode());
        }
    }

    /** @param callable(): mixed $action */
    private function assertValidation(callable $action): void
    {
        try {
            $action();
            self::fail('Incomplete intake data was accepted.');
        } catch (ValidationException $exception) {
            self::assertNotEmpty($exception->errors());
        }
    }

    /** @param callable(): mixed $action */
    private function assertForbidden(callable $action): void
    {
        try {
            $action();
            self::fail('An unauthorized intake action succeeded.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }
}
