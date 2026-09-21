<?php

declare(strict_types=1);

namespace Tests\Feature\Assistance;

use App\Application\AI\AISources;
use App\Application\AI\ApplyAISuggestion;
use App\Infrastructure\Async\OperationRunner;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\AI\Actions\AIRuns;
use App\Modules\AI\Models\AIRun;
use App\Modules\Identity\Actions\ReadActiveIdentity;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommercialDatabase;
use Tests\Support\IdentityHttp;
use Tests\Support\ProjectFixtures;
use Tests\TestCase;

final class AIApiTest extends TestCase
{
    use CommercialDatabase;
    use IdentityHttp;
    use ProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Config::set('ai.enabled', true);
        $this->initializeBrowser();
    }

    public function test_description_is_a_separate_suggestion_and_explicit_application_replays_without_duplicate_mutation(): void
    {
        [$user, $request, $run] = $this->draftRun();
        $original = DB::table('request_drafts')->where('request_id', $request->id)->value('project_description');
        app(OperationRunner::class)->run($run->operation_id);
        self::assertSame($original, DB::table('request_drafts')->where('request_id', $request->id)->value('project_description'));
        $view = $this->browser('GET', '/api/v1/ai-runs/'.$run->id)->assertOk()->assertJsonPath('data.state', 'succeeded');
        self::assertStringNotContainsString('source_text', $view->getContent());
        self::assertStringNotContainsString('document_object_version', $view->getContent());
        $headers = ['If-Match' => $view->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()];
        $first = $this->browser('POST', '/api/v1/ai-runs/'.$run->id.'/applications', [], $headers)->assertOk();
        $this->browser('POST', '/api/v1/ai-runs/'.$run->id.'/applications', [], $headers)->assertOk()->assertExactJson($first->json());
        self::assertSame($view->json('data.suggestion.output.description'), DB::table('request_drafts')->where('request_id', $request->id)->value('project_description'));
        self::assertSame(2, $request->fresh()->lock_version);
        $this->assertDatabaseCount('request_revisions', 0);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'ai.suggestion_applied', 'actor_id' => $user->id]);
        $this->assertDatabaseHas('notifications', ['recipient_id' => $user->id, 'type' => 'ai.suggestion_ready']);
        DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->select('id')->where('code', 'ai.self.apply'))->delete();
        $this->browser('POST', '/api/v1/ai-runs/'.$run->id.'/applications', [], $headers)->assertForbidden();
        self::assertSame(2, $request->fresh()->lock_version);
    }

    public function test_source_edit_rejects_stale_application_without_overwriting_human_changes(): void
    {
        [$user, $request, $run] = $this->draftRun();
        app(OperationRunner::class)->run($run->operation_id);
        $run->refresh();
        app(ManageDraft::class)->update($this->intakeActor($user), $request->id, $this->commercialEtag($request), ['project_description' => 'Changed by the customer.'], (string) Str::uuid7());
        $this->browser('POST', '/api/v1/ai-runs/'.$run->id.'/applications', [], $this->runHeaders($run))->assertConflict();
        self::assertSame('Changed by the customer.', DB::table('request_drafts')->where('request_id', $request->id)->value('project_description'));
        $this->assertDatabaseHas('ai_suggestions', ['run_id' => $run->id, 'state' => 'pending']);
    }

    public function test_submission_closes_source_and_keeps_original_revision_immutable(): void
    {
        [$user, $request, $run] = $this->draftRun();
        app(OperationRunner::class)->run($run->operation_id);
        $run->refresh();
        app(SubmitRequest::class)->handle($this->intakeActor($user), $request->id, $this->commercialEtag($request), (string) Str::uuid7(), (string) Str::uuid7());
        $original = DB::table('request_revisions')->where('request_id', $request->id)->value('project_description');
        $this->browser('POST', '/api/v1/ai-runs/'.$run->id.'/applications', [], $this->runHeaders($run))->assertConflict();
        $this->browser('GET', '/api/v1/ai-runs/'.$run->id)->assertOk();
        self::assertSame($original, DB::table('request_revisions')->where('request_id', $request->id)->value('project_description'));
    }

    public function test_category_suggestion_requires_active_pair_at_generation_and_application(): void
    {
        [, $request, $run] = $this->draftRun('suggest_category');
        app(OperationRunner::class)->run($run->operation_id);
        $view = $this->browser('GET', '/api/v1/ai-runs/'.$run->id)->assertOk();
        $category = $view->json('data.suggestion.output.category_id');
        DB::table('categories')->where('id', $category)->update(['active' => false, 'lock_version' => DB::raw('lock_version + 1')]);
        $this->browser('POST', '/api/v1/ai-runs/'.$run->id.'/applications', [], $this->runHeaders($run->refresh()))->assertUnprocessable();
        self::assertSame(1, $request->fresh()->lock_version);
    }

    public function test_other_customer_cannot_create_list_read_apply_dismiss_or_cancel_sources(): void
    {
        [, $request, $run] = $this->draftRun();
        $other = $this->intakeCustomer();
        $this->initializeBrowser();
        $this->signIn($other)->assertOk();
        $this->browser('POST', '/api/v1/ai-runs', $this->creation($request), ['If-Match' => $this->commercialEtag($request), 'Idempotency-Key' => (string) Str::uuid7()])->assertNotFound();
        $this->browser('GET', '/api/v1/ai-runs', ['parent_type' => 'request', 'parent_id' => $request->id])->assertNotFound();
        $this->browser('GET', '/api/v1/ai-runs/'.$run->id)->assertNotFound();
        foreach (['applications', 'dismissals', 'cancellations'] as $path) {
            $this->browser('POST', '/api/v1/ai-runs/'.$run->id.'/'.$path, [], $this->runHeaders($run))->assertNotFound();
        }
    }

    public function test_worker_rechecks_revoked_permission_and_never_produces_output(): void
    {
        [$user, , $run] = $this->draftRun();
        DB::table('user_roles')->where('user_id', $user->id)->delete();
        app(OperationRunner::class)->run($run->operation_id);
        self::assertContains($run->fresh()->state, ['failed', 'unavailable']);
        $this->assertDatabaseCount('ai_suggestions', 0);
        $this->assertDatabaseCount('ai_provider_attempts', 0);
        $this->browser('GET', '/api/v1/ai-runs/'.$run->id)->assertForbidden();
    }

    public function test_consent_csrf_etag_unknown_fields_and_disabled_provider_are_enforced(): void
    {
        [, $request] = $this->draftRun();
        $body = $this->creation($request);
        $headers = ['If-Match' => $this->commercialEtag($request), 'Idempotency-Key' => (string) Str::uuid7()];
        $this->browser('POST', '/api/v1/ai-runs', [...$body, 'consent' => false], $headers)->assertUnprocessable();
        $this->browser('POST', '/api/v1/ai-runs', [...$body, 'customer_id' => (string) Str::uuid7()], $headers)->assertUnprocessable();
        $this->browser('POST', '/api/v1/ai-runs', $body, ['Idempotency-Key' => (string) Str::uuid7()])->assertStatus(428);
        $this->browser('POST', '/api/v1/ai-runs', $body, $headers, false)->assertForbidden();
        Config::set('ai.enabled', false);
        $this->browser('POST', '/api/v1/ai-runs', $body, $headers)->assertStatus(202)->assertJsonPath('data.state', 'unavailable');
    }

    public function test_cancellation_and_dismissal_are_explicit_and_idempotent(): void
    {
        [, , $run] = $this->draftRun();
        $headers = $this->runHeaders($run);
        $this->browser('POST', '/api/v1/ai-runs/'.$run->id.'/cancellations', [], $headers)->assertOk()->assertJsonPath('data.state', 'cancelled');
        $this->browser('POST', '/api/v1/ai-runs/'.$run->id.'/cancellations', [], $headers)->assertOk();
        app(OperationRunner::class)->run($run->operation_id);
        $this->assertDatabaseCount('ai_suggestions', 0);
        [, , $second] = $this->draftRun();
        app(OperationRunner::class)->run($second->operation_id);
        $headers = $this->runHeaders($second->refresh());
        $this->browser('POST', '/api/v1/ai-runs/'.$second->id.'/dismissals', [], $headers)->assertOk()->assertJsonPath('data.suggestion_state', 'dismissed');
        $this->browser('POST', '/api/v1/ai-runs/'.$second->id.'/dismissals', [], $headers)->assertOk();
    }

    public function test_requirement_application_cannot_mutate_completed_discovery(): void
    {
        $fixture = $this->commercialFixture();
        $staff = $fixture['author'];
        $discoveryId = $this->completeDiscovery($staff, $fixture['request']);
        $request = $fixture['request']->refresh();
        $run = DB::transaction(function () use ($staff, $request): AIRun {
            $identity = app(ReadActiveIdentity::class)->locked($staff->id);
            $source = app(AISources::class)->capture($identity, 'request', $request->id, 'extract_requirements', null, (string) Str::uuid7());

            return app(AIRuns::class)->create($source, 'extract_requirements', (string) Str::uuid7(), (string) Str::uuid7());
        });
        app(OperationRunner::class)->run($run->operation_id);
        self::assertSame('succeeded', $run->fresh()->state);
        $this->assertDatabaseCount('ai_suggestions', 1);
        // The fixture's completed Discovery revision must never be mutated by application.
        $before = DB::table('discovery_requirements')->get()->toJson();
        try {
            DB::transaction(function () use ($staff, $run, $discoveryId): void {
                $identity = app(ReadActiveIdentity::class)->locked($staff->id);
                $output = json_decode(DB::table('ai_suggestions')->where('run_id', $run->id)->value('output'), true, flags: JSON_THROW_ON_ERROR);
                app(ApplyAISuggestion::class)->handle($identity, $run->refresh(), $output, $discoveryId, (string) Str::uuid7());
            });
            self::fail('Completed Discovery must reject application.');
        } catch (HttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        self::assertSame($before, DB::table('discovery_requirements')->get()->toJson());
    }

    private function draftRun(string $purpose = 'improve_description'): array
    {
        $user = $this->intakeCustomer();
        $request = app(ManageDraft::class)->create($this->intakeActor($user), $this->intakeInput(), (string) Str::uuid7());
        $this->initializeBrowser();
        $this->signIn($user)->assertOk();
        $body = [...$this->creation($request), 'purpose' => $purpose];
        $headers = ['If-Match' => $this->commercialEtag($request), 'Idempotency-Key' => (string) Str::uuid7()];
        $response = $this->browser('POST', '/api/v1/ai-runs', $body, $headers)->assertStatus(202)->assertJsonPath('data.state', 'pending');
        $this->browser('POST', '/api/v1/ai-runs', $body, $headers)->assertStatus(202)->assertJsonPath('data.id', $response->json('data.id'));

        return [$user, $request, AIRun::query()->findOrFail($response->json('data.id'))];
    }

    public function test_creation_key_replays_original_source_after_a_later_edit_but_rejects_changed_command(): void
    {
        $user = $this->intakeCustomer();
        $request = app(ManageDraft::class)->create($this->intakeActor($user), $this->intakeInput(), (string) Str::uuid7());
        $this->signIn($user)->assertOk();
        $body = $this->creation($request);
        $headers = ['If-Match' => $this->commercialEtag($request), 'Idempotency-Key' => (string) Str::uuid7()];
        $response = $this->browser('POST', '/api/v1/ai-runs', $body, $headers)->assertStatus(202);
        app(ManageDraft::class)->update($this->intakeActor($user), $request->id, $this->commercialEtag($request), ['project_description' => 'Later source.'], (string) Str::uuid7());
        $this->browser('POST', '/api/v1/ai-runs', $body, $headers)->assertStatus(202)->assertJsonPath('data.id', $response->json('data.id'));
        $this->browser('POST', '/api/v1/ai-runs', [...$body, 'purpose' => 'missing_information'], $headers)->assertConflict();
        $this->assertDatabaseCount('ai_runs', 1);
    }

    public function test_polling_does_not_consume_creation_quota_and_create_limit_preserves_reads(): void
    {
        $user = $this->intakeCustomer();
        $request = app(ManageDraft::class)->create($this->intakeActor($user), $this->intakeInput(), (string) Str::uuid7());
        $this->signIn($user)->assertOk();
        $query = ['parent_type' => 'request', 'parent_id' => $request->id];
        for ($poll = 0; $poll < 21; $poll++) {
            $this->browser('GET', '/api/v1/ai-runs', $query)->assertOk();
        }
        $body = $this->creation($request);
        $headers = ['If-Match' => $this->commercialEtag($request), 'Idempotency-Key' => (string) Str::uuid7()];
        $id = $this->browser('POST', '/api/v1/ai-runs', $body, $headers)->assertStatus(202)->json('data.id');
        for ($attempt = 1; $attempt < 20; $attempt++) {
            $this->browser('POST', '/api/v1/ai-runs', $body, $headers)->assertStatus(202)->assertJsonPath('data.id', $id);
        }
        $limited = $this->browser('POST', '/api/v1/ai-runs', $body, $headers)->assertStatus(429)->assertHeader('Retry-After');
        self::assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        $this->browser('GET', '/api/v1/ai-runs', $query)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->assertDatabaseCount('ai_runs', 1);
        self::assertSame(1000, (int) DB::table('ai_budget_days')->sum('reserved_microusd'));
        self::assertSame(1, DB::table('async_operations')->where('kind', 'ai.generate')->count());
    }

    private function creation(ProjectRequest $request): array
    {
        return ['parent_type' => 'request', 'parent_id' => $request->id, 'purpose' => 'improve_description', 'consent' => true];
    }

    private function runHeaders(AIRun $run): array
    {
        return ['If-Match' => VersionPrecondition::etag($run->id, $run->lock_version), 'Idempotency-Key' => (string) Str::uuid7()];
    }
}
