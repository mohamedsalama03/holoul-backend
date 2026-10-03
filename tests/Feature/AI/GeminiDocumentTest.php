<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Infrastructure\Async\OperationRunner;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\AI\Adapters\GeminiAIProvider;
use App\Modules\AI\Contracts\AIProvider;
use App\Modules\AI\Models\AIRun;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Contracts\DocumentTextExtractor;
use App\Modules\Documents\Models\Document;
use App\Modules\ProjectIntake\Actions\IntakeDocuments;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommercialDatabase;
use Tests\Support\DocumentFixtures;
use Tests\Support\DocumentTextExtractorDouble;
use Tests\Support\GeminiFixture;
use Tests\Support\IdentityHttp;
use Tests\TestCase;

final class GeminiDocumentTest extends TestCase
{
    use CommercialDatabase;
    use DocumentFixtures;
    use GeminiFixture;
    use IdentityHttp;

    private DocumentTextExtractorDouble $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeDocuments();
        $this->configureGemini();
        Config::set('ai.gemini.documents_approved', true);
        $this->extractor = new DocumentTextExtractorDouble;
        $this->extractor->text = 'Synthetic booking requirements. Contact hidden@example.test or +218912345678 password=test-secret';
        app()->instance(DocumentTextExtractor::class, $this->extractor);
        app()->bind(AIProvider::class, GeminiAIProvider::class);
        Http::preventStrayRequests();
        $this->initializeBrowser();
    }

    public function test_unverified_owner_analysis_uses_only_redacted_document_text_once_and_preserves_originals(): void
    {
        [$request, $document] = $this->source();
        $before = DB::table('request_drafts')->where('request_id', $request->id)->first();
        $body = $this->command($request, $document);
        $headers = $this->headers($request);
        $this->browser('POST', '/api/v1/ai-runs', [...$body, 'consent' => false], $headers)->assertUnprocessable();
        $this->browser('POST', '/api/v1/ai-runs', $body, ['Idempotency-Key' => $headers['Idempotency-Key']])->assertStatus(428);
        $this->browser('POST', '/api/v1/ai-runs', $body, $headers, false)->assertForbidden();
        Http::fake(function (Request $request) {
            self::assertSame(0, DB::transactionLevel());
            $text = $request['contents'][0]['parts'][0]['text'];
            self::assertStringContainsString('Synthetic booking requirements.', $text);
            self::assertStringContainsString('[REDACTED]', $text);
            foreach (['hidden@example.test', '+218912345678', 'test-secret', 'DO_NOT_SEND_DRAFT', 'private-filename.pdf'] as $private) {
                self::assertStringNotContainsString($private, json_encode($request->data(), JSON_THROW_ON_ERROR));
            }
            $response = $this->geminiResponse();
            $response['candidates'][0]['content']['parts'][0]['text'] = '{"summary":"A synthetic booking project.","findings":["The budget is unspecified."]}';

            return Http::response($response);
        });
        $created = $this->browser('POST', '/api/v1/ai-runs', $body, $headers)->assertAccepted()->assertJsonPath('data.state', 'pending');
        $this->browser('POST', '/api/v1/ai-runs', $body, $headers)->assertAccepted()->assertJsonPath('data.id', $created->json('data.id'));
        $run = AIRun::query()->findOrFail($created->json('data.id'));
        self::assertSame('', $run->source_text);
        self::assertSame($document->storage_version, $run->document_object_version);
        app(OperationRunner::class)->run($run->operation_id);
        app(OperationRunner::class)->run($run->operation_id);
        $this->browser('GET', '/api/v1/ai-runs/'.$run->id)->assertOk()
            ->assertJsonPath('data.state', 'succeeded')->assertJsonPath('data.suggestion.output.summary', 'A synthetic booking project.');
        self::assertEquals($before, DB::table('request_drafts')->where('request_id', $request->id)->first());
        self::assertSame('available', $document->refresh()->state->value);
        self::assertSame(98, $run->refresh()->actual_cost_microusd);
        $this->assertDatabaseCount('request_revisions', 0);
        self::assertSame(1, $this->extractor->calls);
        Http::assertSentCount(1);
        $this->signIn($this->intakeCustomer(false))->assertOk();
        $this->browser('GET', '/api/v1/ai-runs/'.$run->id)->assertNotFound();
        $this->browser('POST', '/api/v1/ai-runs', $body, $this->headers($request))->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_quarantined_document_is_denied_before_extraction_or_google(): void
    {
        [$request, $document] = $this->source(false);
        Http::fake();
        $this->browser('POST', '/api/v1/ai-runs', $this->command($request, $document), $this->headers($request))->assertConflict();
        $this->assertDatabaseCount('ai_runs', 0);
        self::assertSame(0, $this->extractor->calls);
        Http::assertNothingSent();
    }

    public function test_document_changed_during_extraction_cannot_be_sent_to_google(): void
    {
        [$request, $document] = $this->source();
        Http::fake();
        $run = $this->create($request, $document);
        $this->extractor->afterExtract = static function () use ($request): void {
            DB::table('project_requests')->where('id', $request->id)->increment('lock_version');
        };
        app(OperationRunner::class)->run($run->operation_id);
        self::assertSame('failed', $run->refresh()->state);
        self::assertSame('source_or_authorization_changed', $run->failure_code);
        $this->assertDatabaseCount('ai_provider_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_removing_document_approval_after_admission_prevents_extraction_and_dispatch(): void
    {
        [$request, $document] = $this->source();
        Http::fake();
        $run = $this->create($request, $document);
        Config::set('ai.gemini.documents_approved', false);
        app(OperationRunner::class)->run($run->operation_id);
        self::assertSame('unavailable', $run->refresh()->state);
        self::assertSame('settled', $run->reservation_state);
        self::assertSame(0, $run->actual_cost_microusd);
        self::assertSame(0, (int) DB::table('ai_budget_days')->sum('reserved_microusd'));
        self::assertSame(0, $this->extractor->calls);
        Http::assertNothingSent();
    }

    #[DataProvider('unreadableText')]
    public function test_empty_or_oversized_extraction_is_not_sent_or_silently_truncated(string $text): void
    {
        [$request, $document] = $this->source();
        $this->extractor->text = $text;
        Http::fake();
        $run = $this->create($request, $document);
        app(OperationRunner::class)->run($run->operation_id);
        self::assertSame('failed', $run->refresh()->state);
        $this->assertDatabaseCount('ai_provider_attempts', 0);
        $this->assertDatabaseCount('ai_suggestions', 0);
        Http::assertNothingSent();
    }

    public static function unreadableText(): array
    {
        return [[''], [str_repeat('x', 20001)]];
    }

    /** @return array{ProjectRequest,Document} */
    private function source(bool $scan = true): array
    {
        $user = $this->intakeCustomer(false);
        $actor = $this->intakeActor($user);
        $request = app(ManageDraft::class)->create($actor,
            [...$this->intakeInput(), 'project_description' => 'DO_NOT_SEND_DRAFT'], (string) Str::uuid7());
        $documents = app(IntakeDocuments::class);
        $reservation = DB::transaction(fn () => $documents->reserve($request, $actor,
            VersionPrecondition::etag($request->id, $request->lock_version), 'private-filename.pdf',
            strlen(self::DOCUMENT_PDF), hash('sha256', self::DOCUMENT_PDF), (string) Str::uuid7(), (string) Str::uuid7()));
        $object = $this->uploadFixture($reservation);
        DB::transaction(fn () => app(DocumentService::class)->finalize(
            $documents->owner($request, $actor, (string) Str::uuid7()), $reservation->documentId, $object));
        $document = Document::query()->findOrFail($reservation->documentId);
        if ($scan) {
            app(OperationRunner::class)->run($document->scan_operation_id);
        }
        $this->signIn($user)->assertOk();

        return [$request->refresh(), $document->refresh()];
    }

    /** @return array<string,mixed> */
    private function command(ProjectRequest $request, Document $document): array
    {
        return ['parent_type' => 'request', 'parent_id' => $request->id, 'purpose' => 'analyze_document',
            'document_id' => $document->id, 'consent' => true];
    }

    /** @return array<string,string> */
    private function headers(ProjectRequest $request): array
    {
        return ['If-Match' => VersionPrecondition::etag($request->id, $request->lock_version), 'Idempotency-Key' => (string) Str::uuid7()];
    }

    private function create(ProjectRequest $request, Document $document): AIRun
    {
        $created = $this->browser('POST', '/api/v1/ai-runs', $this->command($request, $document), $this->headers($request))->assertAccepted();

        return AIRun::query()->findOrFail($created->json('data.id'));
    }
}
