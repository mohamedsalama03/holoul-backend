<?php

declare(strict_types=1);

namespace Tests\Feature\Assistance;

use App\Application\AI\AISources;
use App\Application\AI\ApplyAISuggestion;
use App\Infrastructure\Async\OperationRunner;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\AI\Actions\AIRuns;
use App\Modules\AI\Models\AIRun;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Contracts\DocumentTextExtractor;
use App\Modules\Documents\Exceptions\DocumentSourceUnavailable;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Actions\ReadActiveIdentity;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\IntakeDocuments;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\Projects\Actions\ProjectDocuments;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommercialDatabase;
use Tests\Support\DocumentFixtures;
use Tests\Support\DocumentTextExtractorDouble;
use Tests\Support\IdentityHttp;
use Tests\Support\ProjectFixtures;
use Tests\TestCase;

final class AIOwnerBoundaryTest extends TestCase
{
    use CommercialDatabase;
    use DocumentFixtures;
    use IdentityHttp;
    use ProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeDocuments();
        Config::set('ai.enabled', true);
        app()->instance(DocumentTextExtractor::class, new DocumentTextExtractorDouble);
    }

    public function test_project_document_analysis_is_exact_available_and_scoped_to_customer_visibility(): void
    {
        [$fixture, $document] = $this->projectDocument('customer');
        $run = $this->documentRun($fixture, $document);
        self::assertSame($document->expected_sha256, $run->document_checksum);
        self::assertSame($document->storage_version, $run->document_object_version);
        self::assertSame('', $run->source_text);
        self::assertSame($document->id, $run->source_id);
        app(OperationRunner::class)->run($run->operation_id);
        self::assertSame('succeeded', $run->fresh()->state);
        $this->assertDatabaseHas('ai_suggestions', ['run_id' => $run->id, 'state' => 'pending']);
        self::assertSame('planning', $fixture['project']->fresh()->state);
        self::assertSame('available', $document->fresh()->state->value);
    }

    public function test_internal_project_document_is_not_an_ai_source_for_customer(): void
    {
        [$fixture, $document] = $this->projectDocument('internal');
        try {
            $this->documentRun($fixture, $document);
            self::fail('An internal document was disclosed.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->getStatusCode());
        }
        $this->assertDatabaseCount('ai_runs', 0);
    }

    public function test_quarantined_source_and_cross_customer_project_are_denied_before_provider(): void
    {
        [$fixture, $document] = $this->projectDocument('customer', false);
        try {
            $this->documentRun($fixture, $document);
            self::fail('Quarantine must block source creation.');
        } catch (DocumentSourceUnavailable) {
            $this->assertDatabaseCount('ai_runs', 0);
        }
        $fixture['customer'] = $this->intakeCustomer();
        try {
            $this->documentRun($fixture, $document);
            self::fail('Another customer must not access the Project.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->getStatusCode());
        }
        $this->assertDatabaseCount('ai_provider_attempts', 0);
    }

    public function test_project_mutation_after_source_capture_prevents_provider_dispatch(): void
    {
        [$fixture, $document] = $this->projectDocument('customer');
        $run = $this->documentRun($fixture, $document);
        $this->projectCommand($fixture['author'], $fixture['project'], 'project.update.publish', input: ['content' => 'Human source context changed.']);
        app(OperationRunner::class)->run($run->operation_id);
        self::assertSame('failed', $run->fresh()->state);
        $this->assertDatabaseCount('ai_provider_attempts', 0);
        $this->assertDatabaseCount('ai_suggestions', 0);
    }

    public function test_explicit_requirement_application_appends_proposed_items_through_owner(): void
    {
        $fixture = $this->commercialFixture();
        $revision = $this->commercialCommand($fixture['author'], $fixture['request'], 'discovery.create', input: ['summary' => 'Mutable Discovery.']);
        $id = $revision['data']['id'];
        $this->commercialCommand($fixture['author'], $fixture['request'], 'discovery.requirements', $id, $this->commercialRequirements());
        $request = $fixture['request']->refresh();
        $run = DB::transaction(function () use ($fixture, $request): AIRun {
            $identity = app(ReadActiveIdentity::class)->locked($fixture['author']->id);
            $source = app(AISources::class)->capture($identity, 'request', $request->id, 'extract_requirements', null, (string) Str::uuid7());

            return app(AIRuns::class)->create($source, 'extract_requirements', (string) Str::uuid7(), (string) Str::uuid7());
        });
        app(OperationRunner::class)->run($run->operation_id);
        $this->assertDatabaseCount('discovery_requirements', 1);
        DB::transaction(function () use ($fixture, $run, $id): void {
            $identity = app(ReadActiveIdentity::class)->locked($fixture['author']->id);
            $output = json_decode(DB::table('ai_suggestions')->where('run_id', $run->id)->value('output'), true, flags: JSON_THROW_ON_ERROR);
            app(ApplyAISuggestion::class)->handle($identity, $run->refresh(), $output, $id, (string) Str::uuid7());
            app(AIRuns::class)->applyRecord($run, $identity->id, (string) Str::uuid7());
        });
        $this->assertDatabaseCount('discovery_requirements', 2);
        $this->assertDatabaseHas('discovery_requirements', ['revision_id' => $id, 'title' => 'Customer portal', 'status' => 'confirmed']);
        $this->assertDatabaseHas('discovery_requirements', ['revision_id' => $id, 'status' => 'proposed']);
        $this->assertDatabaseHas('ai_suggestions', ['run_id' => $run->id, 'state' => 'applied']);
        $this->assertDatabaseHas('discovery_revisions', ['id' => $id, 'state' => 'draft']);
        $this->assertDatabaseCount('discovery_signoffs', 0);
    }

    #[DataProvider('projectHistoryActors')]
    public function test_project_document_history_and_creation_replay_require_current_document_permission(string $actorKey, string $permission): void
    {
        [$fixture, $document] = $this->projectDocument('customer');
        [$body, $headers, $run, $output] = $this->historyRun($fixture[$actorKey], 'project', $fixture['project']->id, $document->id);
        $this->projectCommand($fixture['author'], $fixture['project'], 'project.update.publish', input: ['content' => 'Later delivery information.']);
        // Historical output survives an unrelated source version change while document access remains valid.
        $this->browser('GET', '/api/v1/ai-runs/'.$run->id)->assertOk()->assertJsonPath('data.suggestion.output', $output);
        $this->browser('POST', '/api/v1/ai-runs', $body, $headers)->assertStatus(202)->assertJsonPath('data.suggestion.output', $output);
        $this->assertHistoryRevocation($body, $headers, $run, $permission);
        $prefix = $actorKey === 'customer' ? '/api/v1/projects/' : '/api/v1/admin/projects/';
        $this->browser('GET', $prefix.$fixture['project']->id)->assertOk();
    }

    public static function projectHistoryActors(): array
    {
        return [['customer', 'projects.self.documents.read'], ['author', 'projects.documents.read']];
    }

    public function test_intake_document_history_and_creation_replay_require_current_download_permission(): void
    {
        $customer = $this->intakeCustomer();
        $actor = $this->intakeActor($customer);
        $request = app(ManageDraft::class)->create($actor, $this->intakeInput(), (string) Str::uuid7());
        $documents = app(IntakeDocuments::class);
        $reservation = DB::transaction(fn () => $documents->reserve($request, $actor, $this->commercialEtag($request), 'source.pdf',
            strlen(self::DOCUMENT_PDF), hash('sha256', self::DOCUMENT_PDF), (string) Str::uuid7(), (string) Str::uuid7()));
        $object = $this->uploadFixture($reservation);
        DB::transaction(fn () => app(DocumentService::class)->finalize($documents->owner($request, $actor, (string) Str::uuid7()), $reservation->documentId, $object));
        $document = Document::query()->findOrFail($reservation->documentId);
        app(OperationRunner::class)->run($document->scan_operation_id);
        app(SubmitRequest::class)->handle($actor, $request->id, $this->commercialEtag($request->refresh()), (string) Str::uuid7(), (string) Str::uuid7());
        $staff = $this->intakeStaff('super_admin');
        [$body, $headers, $run] = $this->historyRun($staff, 'request', $request->id, $document->id);
        $this->assertHistoryRevocation($body, $headers, $run, 'documents.download');
        $this->browser('GET', '/api/v1/admin/project-requests/'.$request->id)->assertOk();
    }

    private function historyRun(User $actor, string $parentType, string $parentId, string $documentId): array
    {
        $this->initializeBrowser();
        if ($actor->kind === 'staff') {
            $this->signIn($actor)->assertAccepted();
            $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
            $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
        } else {
            $this->signIn($actor)->assertOk();
        }
        $version = DB::table($parentType === 'project' ? 'projects' : 'project_requests')->where('id', $parentId)->value('lock_version');
        $body = ['parent_type' => $parentType, 'parent_id' => $parentId, 'purpose' => 'analyze_document', 'document_id' => $documentId, 'consent' => true];
        $headers = ['If-Match' => VersionPrecondition::etag($parentId, $version), 'Idempotency-Key' => (string) Str::uuid7()];
        $created = $this->browser('POST', '/api/v1/ai-runs', $body, $headers)->assertStatus(202);
        $run = AIRun::query()->findOrFail($created->json('data.id'));
        app(OperationRunner::class)->run($run->operation_id);
        $view = $this->browser('GET', '/api/v1/ai-runs/'.$run->id)->assertOk()->assertJsonPath('data.state', 'succeeded');

        return [$body, $headers, $run->refresh(), $view->json('data.suggestion.output')];
    }

    private function assertHistoryRevocation(array $body, array $headers, AIRun $run, string $permission): void
    {
        DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->select('id')->where('code', $permission))->delete();
        $this->browser('GET', '/api/v1/ai-runs/'.$run->id)->assertForbidden()->assertJsonMissingPath('data.suggestion');
        $this->browser('POST', '/api/v1/ai-runs', $body, $headers)->assertForbidden()->assertJsonMissingPath('data.suggestion');
        // Requester-scoped metadata and non-content decisions remain available through current parent access.
        $this->browser('GET', '/api/v1/ai-runs', ['parent_type' => $body['parent_type'], 'parent_id' => $body['parent_id']])
            ->assertOk()->assertJsonPath('data.0.id', $run->id)->assertJsonMissingPath('data.0.suggestion');
        $decisionHeaders = ['If-Match' => VersionPrecondition::etag($run->id, $run->lock_version), 'Idempotency-Key' => (string) Str::uuid7()];
        $this->browser('POST', '/api/v1/ai-runs/'.$run->id.'/dismissals', [], $decisionHeaders)->assertOk()->assertJsonMissingPath('data.suggestion');
        $this->browser('POST', '/api/v1/ai-runs/'.$run->id.'/dismissals', [], $decisionHeaders)->assertOk()->assertJsonMissingPath('data.suggestion');
        $this->assertDatabaseCount('ai_runs', 1);
        $this->assertDatabaseHas('ai_suggestions', ['run_id' => $run->id, 'state' => 'dismissed']);
    }

    private function projectDocument(string $visibility, bool $scan = true): array
    {
        $fixture = $this->projectFixture();
        /** @var Project $project */
        $project = $fixture['project'];
        $actor = $this->projectActor($fixture['author']);
        $reservation = DB::transaction(fn () => app(ProjectDocuments::class)->reserve($project, $actor,
            VersionPrecondition::etag($project->id, $project->lock_version), 'source.pdf', strlen(self::DOCUMENT_PDF),
            hash('sha256', self::DOCUMENT_PDF), $visibility, (string) Str::uuid7(), (string) Str::uuid7()));
        $object = $this->uploadFixture($reservation);
        DB::transaction(fn () => app(ProjectDocuments::class)->finalize($project, $actor, $reservation->documentId, $object,
            VersionPrecondition::etag($project->id, $project->lock_version), (string) Str::uuid7()));
        $document = Document::query()->findOrFail($reservation->documentId);
        if ($scan) {
            app(OperationRunner::class)->run($document->scan_operation_id);
        }

        return [$fixture, $document->refresh()];
    }

    private function documentRun(array $fixture, Document $document): AIRun
    {
        return DB::transaction(function () use ($fixture, $document): AIRun {
            $identity = app(ReadActiveIdentity::class)->locked($fixture['customer']->id);
            $source = app(AISources::class)->capture($identity, 'project', $fixture['project']->id, 'analyze_document', $document->id, (string) Str::uuid7());

            return app(AIRuns::class)->create($source, 'analyze_document', (string) Str::uuid7(), (string) Str::uuid7());
        });
    }
}
