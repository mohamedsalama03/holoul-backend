<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Configuration\AIConfiguration;
use App\Infrastructure\Configuration\ProductionConfiguration;
use App\Modules\AI\Actions\AIAvailability;
use App\Modules\AI\Actions\AISchema;
use App\Modules\AI\Adapters\GeminiAIProvider;
use App\Modules\AI\Data\AIInput;
use App\Modules\AI\Exceptions\AIProviderFailure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GeminiFixture;
use Tests\TestCase;

final class GeminiAIProviderTest extends TestCase
{
    use GeminiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureGemini();
        Http::preventStrayRequests();
    }

    public function test_request_is_text_only_fixed_destination_structured_and_counts_thinking_in_cost(): void
    {
        Http::fake(function (Request $request, array $options) {
            self::assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent', $request->url());
            self::assertSame(['synthetic-gemini-key-never-real'], $request->header('x-goog-api-key'));
            self::assertFalse($options['allow_redirects']);
            self::assertTrue($options['verify']);
            self::assertSame(45, $options['timeout']);
            self::assertSame(5, $options['connect_timeout']);
            $data = $request->data();
            self::assertSame(['systemInstruction', 'contents', 'generationConfig'], array_keys($data));
            self::assertSame([['role' => 'user', 'parts' => [['text' => 'اكتب فكرة مشروع لتطبيق حجز مواعيد.']]]], $data['contents']);
            // The REST field is a protobuf enum, unlike the MIME string in SDK examples.
            self::assertSame('APPLICATION_JSON', $data['generationConfig']['responseFormat']['text']['mimeType']);
            self::assertStringContainsString('Required output language: Arabic', $data['systemInstruction']['parts'][0]['text']);
            self::assertSame(4096, $data['generationConfig']['maxOutputTokens']);

            return Http::response($this->geminiResponse());
        });
        $result = app(GeminiAIProvider::class)->generate($this->input());
        self::assertSame(101, $result->inputTokens);
        self::assertSame(27, $result->outputTokens);
        self::assertSame(98, $result->costMicrousd);
        self::assertSame('synthetic-generation-1', $result->operationId);
        self::assertArrayHasKey('description', app(AISchema::class)->validate('improve_description', $result->json, 32000));
        Http::assertSentCount(1);
    }

    #[DataProvider('rejectedConfiguration')]
    public function test_unapproved_or_unbounded_configuration_never_sends_data(string $key, mixed $value): void
    {
        Config::set($key, $value);
        Http::fake();
        try {
            app(GeminiAIProvider::class)->generate($this->input());
            self::fail('Unsafe provider configuration accepted.');
        } catch (AIProviderFailure $failure) {
            self::assertSame('provider_unavailable', $failure->safeCode);
            self::assertFalse($failure->uncertain);
        }
        Http::assertNothingSent();
    }

    public static function rejectedConfiguration(): array
    {
        return [
            ['ai.enabled', false], ['ai.gemini.approved', false], ['ai.gemini.api_key', ''],
            ['ai.gemini.api_key', "secret-header\r\nInjection:value"],
            ['ai.model', 'other-model'], ['ai.allowed_models', ['*']],
            ['ai.daily_budget_microusd', 1000001], ['ai.reserved_cost_microusd', 1000],
            ['operations.deployment_profile', 'production'], ['ai.timeout_seconds', 61],
            ['ai.runs_per_user_per_day', 21],
        ];
    }

    #[DataProvider('responses')]
    public function test_errors_and_ambiguous_outputs_are_safe_and_never_automatically_retried(int $status, string $kind, bool $uncertain): void
    {
        $body = $this->geminiResponse();
        if ($kind === 'invalid-usage') {
            $body['usageMetadata']['thoughtsTokenCount'] = -1;
        } elseif ($kind === 'missing-usage') {
            unset($body['usageMetadata']);
        } elseif ($kind === 'truncated') {
            $body['candidates'][0]['finishReason'] = 'MAX_TOKENS';
        } elseif ($kind === 'too-large') {
            $body = str_repeat('private-response', 140000);
        } elseif ($kind === 'malformed') {
            $body = 'private-response not json';
        } elseif ($kind === 'error') {
            $body = ['error' => ['message' => 'private-response synthetic-gemini-key-never-real']];
        }
        Http::fake(['*' => Http::response($body, $status)]);
        try {
            app(GeminiAIProvider::class)->generate($this->input());
            self::fail('Invalid generation accepted.');
        } catch (AIProviderFailure $failure) {
            self::assertSame($uncertain, $failure->uncertain);
            self::assertFalse($failure->retryable);
            self::assertNull($failure->getPrevious());
            self::assertStringNotContainsString('private-response', $failure->getMessage());
            self::assertStringNotContainsString('synthetic-gemini-key', $failure->getMessage());
        }
        Http::assertSentCount(1);
    }

    public static function responses(): array
    {
        return [[400, 'error', false], [401, 'error', false], [403, 'error', false], [429, 'error', false],
            [503, 'error', true], [302, 'error', true], [200, 'invalid-usage', true],
            [200, 'missing-usage', true], [200, 'truncated', true], [200, 'too-large', true], [200, 'malformed', true]];
    }

    public function test_transport_failure_is_ambiguous_without_attaching_secret_bearing_exception(): void
    {
        Http::fake(['*' => Http::failedConnection('private-response synthetic-gemini-key-never-real')]);
        try {
            app(GeminiAIProvider::class)->generate($this->input());
            self::fail('Transport failure accepted.');
        } catch (AIProviderFailure $failure) {
            self::assertSame('provider_outcome_unknown', $failure->safeCode);
            self::assertTrue($failure->uncertain);
            self::assertFalse($failure->retryable);
            self::assertNull($failure->getPrevious());
        }
    }

    public function test_document_analysis_without_separate_approval_cannot_use_the_adapter(): void
    {
        Http::fake();
        $this->expectException(AIProviderFailure::class);
        try {
            app(GeminiAIProvider::class)->generate($this->input('analyze_document'));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_approved_document_analysis_uses_only_text_and_a_grounded_structured_summary(): void
    {
        Config::set('ai.gemini.documents_approved', true);
        Http::fake(function (Request $request) {
            $data = $request->data();
            self::assertSame(['systemInstruction', 'contents', 'generationConfig'], array_keys($data));
            self::assertSame(['text'], array_keys($data['contents'][0]['parts'][0]));
            self::assertSame(['summary', 'findings'], $data['generationConfig']['responseFormat']['text']['schema']['required']);
            self::assertStringContainsString('extracted document text', $data['systemInstruction']['parts'][0]['text']);
            self::assertStringContainsString('untrusted source material', $data['systemInstruction']['parts'][0]['text']);
            $body = $this->geminiResponse();
            $body['candidates'][0]['content']['parts'][0]['text'] = json_encode([
                'summary' => 'وثيقة مشروع لحجز المواعيد ومتابعتها.',
                'findings' => ['لم تحدد الوثيقة ميزانية المشروع.'],
            ], JSON_THROW_ON_ERROR);

            return Http::response($body);
        });
        $result = app(GeminiAIProvider::class)->generate($this->input('analyze_document'));
        $output = app(AISchema::class)->validate('analyze_document', $result->json, 32000);
        self::assertSame('وثيقة مشروع لحجز المواعيد ومتابعتها.', $output['summary']);
        self::assertSame(98, $result->costMicrousd);
        Http::assertSentCount(1);
    }

    public function test_document_approval_does_not_enable_other_purposes_or_delivery_content(): void
    {
        Config::set('ai.gemini.documents_approved', true);
        self::assertTrue(AIAvailability::allows('analyze_document', 'intake_draft', 'synthetic-document'));
        self::assertFalse(AIAvailability::allows('analyze_document', 'intake_draft', null));
        self::assertFalse(AIAvailability::allows('analyze_document', 'intake_revision', 'synthetic-document'));
        self::assertFalse(AIAvailability::allows('analyze_document', 'project_document', 'synthetic-document'));
        Http::fake();
        foreach (['suggest_category', 'extract_requirements', 'missing_information'] as $purpose) {
            try {
                app(GeminiAIProvider::class)->generate($this->input($purpose));
                self::fail('Unapproved purpose was accepted.');
            } catch (AIProviderFailure $failure) {
                self::assertSame('provider_unavailable', $failure->safeCode);
            }
        }
        Http::assertNothingSent();
    }

    public function test_external_scope_and_startup_guard_require_the_reviewed_local_configuration(): void
    {
        // This pure configuration check describes the approved runtime topology,
        // independent of the separate PostgreSQL test container's DNS aliases.
        Config::set(['database.connections.pgsql.host' => 'postgres',
            'database.redis.default.host' => 'redis', 'database.redis.cache.host' => 'redis']);
        self::assertSame([], app(ProductionConfiguration::class)->violations());
        self::assertTrue(AIAvailability::allows('improve_description', 'intake_draft', null));
        self::assertFalse(AIAvailability::allows('improve_description', 'intake_draft', 'synthetic-document'));
        self::assertFalse(AIAvailability::allows('improve_description', 'intake_revision', null));
        self::assertFalse(AIAvailability::allows('analyze_document', 'project_document', 'synthetic-document'));
        Config::set('ai.gemini.approved', false);
        self::assertContains('external_ai_not_approved', app(ProductionConfiguration::class)->violations());
        Config::set('ai.gemini.approved', true);
        Config::set('operations.deployment_profile', 'production');
        self::assertContains('external_ai_not_approved', app(ProductionConfiguration::class)->violations());
    }

    public function test_arabic_draft_cannot_silently_become_an_english_suggestion_or_trigger_a_retry(): void
    {
        $body = $this->geminiResponse();
        $body['candidates'][0]['content']['parts'][0]['text'] = '{"description":"A booking website for bicycle maintenance."}';
        Http::fake(['*' => Http::response($body)]);
        try {
            app(GeminiAIProvider::class)->generate($this->input());
            self::fail('English translation of the Arabic source was accepted.');
        } catch (AIProviderFailure $failure) {
            self::assertSame('invalid_provider_output', $failure->safeCode);
            self::assertTrue($failure->uncertain);
            self::assertFalse($failure->retryable);
        }
        Http::assertSentCount(1);
    }

    public function test_english_draft_keeps_english_output_without_forcing_arabic(): void
    {
        Http::fake(function (Request $request) {
            self::assertStringNotContainsString('Required output language: Arabic', $request->data()['systemInstruction']['parts'][0]['text']);
            $body = $this->geminiResponse();
            $body['candidates'][0]['content']['parts'][0]['text'] = '{"description":"A booking website for bicycle maintenance."}';

            return Http::response($body);
        });
        $result = app(GeminiAIProvider::class)->generate(new AIInput('synthetic-english-run', 'improve_description',
            AIConfiguration::GEMINI_MODEL, 'A booking website for bicycle maintenance.', [], 5, 45, 32000));
        self::assertSame('A booking website for bicycle maintenance.', app(AISchema::class)->validate('improve_description', $result->json, 32000)['description']);
        Http::assertSentCount(1);
    }

    private function input(string $purpose = 'improve_description'): AIInput
    {
        return new AIInput('synthetic-run-not-transmitted', $purpose, AIConfiguration::GEMINI_MODEL,
            'اكتب فكرة مشروع لتطبيق حجز مواعيد.', [], 5, 45, 32000);
    }
}
