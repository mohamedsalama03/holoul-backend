<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Infrastructure\Async\OperationHandlerRegistry;
use App\Infrastructure\Async\OperationRunner;
use App\Modules\AI\Actions\AIRuns;
use App\Modules\AI\Actions\GenerateAI;
use App\Modules\AI\Adapters\GeminiAIProvider;
use App\Modules\AI\Contracts\AIContextVerifier;
use App\Modules\AI\Contracts\AIProvider;
use App\Modules\AI\Data\AISource;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\AIContextDouble;
use Tests\Support\CommercialDatabase;
use Tests\Support\GeminiFixture;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class GeminiWorkflowTest extends TestCase
{
    use CommercialDatabase;
    use GeminiFixture;
    use IntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->configureGemini();
        Http::preventStrayRequests();
        $this->app->instance(AIContextVerifier::class, new AIContextDouble);
        $this->app->bind(AIProvider::class, GeminiAIProvider::class);
    }

    public function test_real_durable_flow_redacts_contacts_keeps_original_and_settles_once(): void
    {
        $source = $this->source();
        Http::fake(['*' => Http::response($this->geminiResponse())]);
        $key = (string) Str::uuid7();
        $runs = app(AIRuns::class);
        $run = $runs->create($source, 'improve_description', $key, (string) Str::uuid7());
        self::assertSame($run->id, $runs->create($source, 'improve_description', $key, (string) Str::uuid7())->id);
        self::assertSame(100000, (int) DB::table('ai_budget_days')->sum('reserved_microusd'));
        $runner = $this->runner();
        $runner->run($run->operation_id);
        $runner->run($run->operation_id);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $text = $request['contents'][0]['parts'][0]['text'];

            return str_contains($text, '[REDACTED]') && ! str_contains($text, 'private@example.test')
                && ! str_contains($text, '+218912345678') && ! str_contains($text, 'test-secret');
        });
        self::assertSame('succeeded', $run->refresh()->state);
        self::assertSame(98, $run->actual_cost_microusd);
        self::assertSame(0, (int) DB::table('ai_budget_days')->sum('reserved_microusd'));
        self::assertSame(98, (int) DB::table('ai_budget_days')->sum('spent_microusd'));
        self::assertSame($source->text, DB::table('request_drafts')->where('id', $source->sourceId)->value('project_description'));
        $this->assertDatabaseHas('ai_suggestions', ['run_id' => $run->id, 'state' => 'pending']);
    }

    public function test_ambiguous_generation_is_not_redispatched_and_its_reservation_remains(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $run = app(AIRuns::class)->create($this->source(), 'improve_description', (string) Str::uuid7(), (string) Str::uuid7());
        $runner = $this->runner();
        $runner->run($run->operation_id);
        $runner->run($run->operation_id);
        self::assertSame('failed', $run->refresh()->state);
        self::assertSame('uncertain', $run->reservation_state);
        self::assertSame('provider_outcome_unknown', $run->failure_code);
        self::assertSame(100000, (int) DB::table('ai_budget_days')->sum('reserved_microusd'));
        $this->assertDatabaseCount('ai_provider_attempts', 1);
        $this->assertDatabaseCount('ai_suggestions', 0);
    }

    public function test_out_of_scope_work_has_no_dispatch_and_no_budget_reservation(): void
    {
        Http::fake();
        foreach (['suggest_category', 'extract_requirements', 'missing_information'] as $purpose) {
            $run = app(AIRuns::class)->create($this->source(), $purpose, (string) Str::uuid7(), (string) Str::uuid7());
            self::assertSame('unavailable', $run->state);
            self::assertNull($run->operation_id);
            self::assertSame(0, $run->reserved_cost_microusd);
        }
        Http::assertNothingSent();
        self::assertSame(0, (int) DB::table('ai_budget_days')->sum('reserved_microusd'));
    }

    public function test_disabling_approval_after_admission_prevents_the_external_call(): void
    {
        Http::fake();
        $run = app(AIRuns::class)->create($this->source(), 'improve_description', (string) Str::uuid7(), (string) Str::uuid7());
        Config::set('ai.gemini.approved', false);
        $this->runner()->run($run->operation_id);
        self::assertSame('unavailable', $run->refresh()->state);
        Http::assertNothingSent();
        self::assertSame(0, (int) DB::table('ai_budget_days')->sum('reserved_microusd'));
    }

    public function test_daily_reservation_cap_is_enforced_before_any_google_request(): void
    {
        Config::set('ai.daily_budget_microusd', 100000);
        Http::fake();
        $one = app(AIRuns::class)->create($this->source(), 'improve_description', (string) Str::uuid7(), (string) Str::uuid7());
        $two = app(AIRuns::class)->create($this->source(), 'improve_description', (string) Str::uuid7(), (string) Str::uuid7());
        self::assertSame('pending', $one->state);
        self::assertSame('unavailable', $two->state);
        self::assertSame('daily_budget_exhausted', $two->failure_code);
        Http::assertNothingSent();
        self::assertSame(100000, (int) DB::table('ai_budget_days')->sum('reserved_microusd'));
    }

    private function source(): AISource
    {
        $customer = $this->intakeCustomer();
        $text = 'A booking portal. Contact private@example.test or +218912345678 password=test-secret';
        $request = app(ManageDraft::class)->create($this->intakeActor($customer),
            [...$this->intakeInput(), 'project_description' => $text], (string) Str::uuid7());

        return new AISource($customer->id, $request->customer_id, 'request', $request->id, 'intake_draft',
            DB::table('request_drafts')->where('request_id', $request->id)->value('id'), $request->lock_version,
            hash('sha256', $text), $text);
    }

    private function runner(): OperationRunner
    {
        $registry = new OperationHandlerRegistry;
        $registry->register('ai.generate', app(GenerateAI::class));

        return new OperationRunner($registry);
    }
}
