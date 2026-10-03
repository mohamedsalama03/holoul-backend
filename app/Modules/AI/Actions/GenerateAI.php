<?php

declare(strict_types=1);

namespace App\Modules\AI\Actions;

use App\Infrastructure\Async\AsyncOperation;
use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Infrastructure\Async\OperationPolicy;
use App\Infrastructure\Async\PermanentOperationFailure;
use App\Infrastructure\Async\RetryableOperationFailure;
use App\Infrastructure\Clock\DatabaseClock;
use App\Modules\AI\Contracts\AIContextVerifier;
use App\Modules\AI\Contracts\AIProvider;
use App\Modules\AI\Data\AIInput;
use App\Modules\AI\Data\AIResult;
use App\Modules\AI\Exceptions\AIProviderFailure;
use App\Modules\AI\Models\AIRun;
use App\Modules\Documents\Exceptions\InspectionUnavailable;
use App\Modules\Documents\Exceptions\StorageUnavailable;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class GenerateAI implements OperationHandler
{
    public function __construct(private AIRuns $runs, private AIContextVerifier $context, private AIProvider $provider,
        private AIInputMinimizer $minimizer, private AISchema $schema) {}

    public function execute(OperationClaim $operation): Closure
    {
        $run = AIRun::query()->find($operation->references['ai_run_id'] ?? '') ?? throw new PermanentOperationFailure('ai_run_missing');
        if ($run->operation_id !== $operation->id) {
            throw new PermanentOperationFailure('ai_operation_mismatch');
        }
        if ($run->state === 'cancelled' && $run->reservation_state === 'uncertain' && $run->dispatch_fence !== null) {
            return $this->failure($operation, $run->id, 'provider_outcome_unknown', true);
        }
        if (! in_array($run->state, ['pending', 'processing'], true)) {
            return static function (): void {};
        }
        // A persisted dispatch without a durable outcome may have been charged. Never redispatch it.
        if ($run->dispatch_fence !== null) {
            return $this->failure($operation, $run->id, 'provider_outcome_unknown', true);
        }
        if (! AIAvailability::allows($run->purpose, $run->source_type, $run->document_id)
            || Config::string('ai.driver') !== $run->provider
            || ! in_array($run->provider, Config::array('ai.allowed_providers'), true)
            || ! in_array($run->model, Config::array('ai.allowed_models'), true)) {
            return $this->failure($operation, $run->id, 'provider_unavailable', false);
        }
        try {
            DB::transaction(fn () => $this->context->verify($run));
            $input = $this->minimizer->handle($this->context->input($run), Config::integer('ai.maximum_input_characters'));
        } catch (InspectionUnavailable|StorageUnavailable) {
            if ($operation->attempt < OperationPolicy::maxAttempts($operation->kind)) {
                throw new RetryableOperationFailure('source_temporarily_unavailable');
            }

            return $this->failure($operation, $run->id, 'source_unavailable', false);
        } catch (Throwable) {
            return $this->failure($operation, $run->id, 'source_or_authorization_changed', false);
        }
        try {
            $dispatched = DB::transaction(function () use ($run, $operation): bool {
                if (AsyncOperation::query()->whereKey($operation->id)->where('state', 'running')->where('fence', $operation->fence)
                    ->where('lease_expires_at', '>', DB::raw('clock_timestamp()'))->lockForUpdate()->first() === null) {
                    return false;
                }
                $this->context->verify($run);
                $current = AIRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($current->state !== 'pending' || $current->dispatch_fence !== null) {
                    return false;
                }
                $this->runs->change($current, ['state' => 'processing', 'dispatch_fence' => $operation->fence,
                    'started_at' => DatabaseClock::now(), 'failure_code' => null]);
                DB::table('ai_provider_attempts')->insert(['id' => (string) Str::uuid7(), 'run_id' => $current->id,
                    'fence' => $operation->fence, 'outcome' => 'started']);

                return true;
            });
        } catch (Throwable) {
            return $this->failure($operation, $run->id, 'source_or_authorization_changed', false);
        }
        if (! $dispatched) {
            return static function (): void {};
        }
        try {
            $result = $this->provider->generate(new AIInput($run->id, $run->purpose, $run->model, $input, $run->taxonomy,
                Config::integer('ai.connect_timeout_seconds'), Config::integer('ai.timeout_seconds'), Config::integer('ai.maximum_output_bytes')));
        } catch (AIProviderFailure $failure) {
            $code = $this->safeCode($failure->safeCode);
            if ($failure->retryable && ! $failure->uncertain && $operation->attempt < OperationPolicy::maxAttempts($operation->kind)) {
                $retry = DB::transaction(function () use ($run, $operation, $failure, $code): bool {
                    $current = AIRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                    if ($current->state === 'cancelled') {
                        $this->runs->settle($current, 0);
                        $this->attempt($run->id, $operation->fence, 'cancelled', $code, $failure->operationId);

                        return false;
                    }
                    if ($current->state !== 'processing' || $current->dispatch_fence !== $operation->fence) {
                        return false;
                    }
                    $this->runs->change($current, ['state' => 'pending', 'dispatch_fence' => null, 'failure_code' => $code]);
                    $this->attempt($run->id, $operation->fence, 'retryable', $code, $failure->operationId, min(3600, max(0, $failure->retryAfterSeconds)));

                    return true;
                });
                if ($retry) {
                    throw new RetryableOperationFailure($code, min(3600, max(0, $failure->retryAfterSeconds)));
                }

                return static function (): void {};
            }

            return $this->failure($operation, $run->id, $code, $failure->uncertain, 0, $failure->operationId);
        } catch (Throwable) {
            return $this->failure($operation, $run->id, 'provider_outcome_unknown', true);
        }
        if ($result->costMicrousd < 0 || $result->costMicrousd > $run->reserved_cost_microusd
            || $result->inputTokens < 0 || $result->inputTokens > 1000000 || $result->outputTokens < 0 || $result->outputTokens > 1000000) {
            return $this->failure($operation, $run->id, 'invalid_provider_usage', true, 0, $result->operationId);
        }
        try {
            $output = $this->schema->validate($run->purpose, $result->json, Config::integer('ai.maximum_output_bytes'));
        } catch (AIProviderFailure $failure) {
            return $this->failure($operation, $run->id, $this->safeCode($failure->safeCode), false, $result->costMicrousd, $result->operationId);
        }

        return $this->success($operation, $run->id, $result, $output);
    }

    /** @param array<string,mixed> $output
     * @return Closure():void
     */
    private function success(OperationClaim $operation, string $id, AIResult $result, array $output): Closure
    {
        return function () use ($operation, $id, $result, $output): void {
            $snapshot = AIRun::query()->findOrFail($id);
            $authorized = true;
            try {
                $this->context->verify($snapshot);
                if ($snapshot->purpose === 'suggest_category') {
                    $category = $output['category_id'] ?? null;
                    $subcategory = $output['subcategory_id'] ?? null;
                    if (! is_string($category) || ! is_string($subcategory)) {
                        throw new \LogicException('Invalid validated taxonomy.');
                    }
                    $this->context->validateTaxonomy($category, $subcategory);
                }
            } catch (Throwable) {
                $authorized = false;
            }
            $run = AIRun::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($run->state === 'cancelled') {
                $this->runs->settle($run, $result->costMicrousd);
                $this->attempt($id, $operation->fence, 'cancelled', null, $result->operationId);

                return;
            }
            if ($run->state !== 'processing' || $run->dispatch_fence !== $operation->fence) {
                return;
            }
            $this->runs->change($run, ['input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens,
                'provider_operation_id' => $this->operationId($result->operationId)]);
            if (! $authorized) {
                $this->attempt($id, $operation->fence, 'failed', 'source_or_authorization_changed', $result->operationId);
                $this->runs->finish($run, 'failed', 'source_or_authorization_changed', false, $result->costMicrousd, $this->correlation($run, $operation));

                return;
            }
            DB::table('ai_suggestions')->insert(['id' => (string) Str::uuid7(), 'run_id' => $run->id, 'output' => json_encode($output, JSON_THROW_ON_ERROR)]);
            $this->attempt($id, $operation->fence, 'succeeded', null, $result->operationId);
            $this->runs->finish($run, 'succeeded', null, false, $result->costMicrousd, $this->correlation($run, $operation));
        };
    }

    /** @return Closure():void */
    private function failure(OperationClaim $operation, string $id, string $code, bool $uncertain, int $cost = 0, ?string $providerId = null): Closure
    {
        return function () use ($operation, $id, $code, $uncertain, $cost, $providerId): void {
            $run = AIRun::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->attempt($id, $run->dispatch_fence ?? $operation->fence, $uncertain ? 'uncertain' : 'failed', $code, $providerId);
            if ($run->state === 'cancelled') {
                if (! $uncertain) {
                    $this->runs->settle($run, $cost);
                }

                return;
            }
            if ($providerId !== null) {
                $this->runs->change($run, ['provider_operation_id' => $this->operationId($providerId)]);
            }
            $this->runs->finish($run, $code === 'provider_unavailable' ? 'unavailable' : 'failed', $code, $uncertain, $cost, $this->correlation($run, $operation));
        };
    }

    private function attempt(string $id, int $fence, string $outcome, ?string $code, ?string $providerId, int $retryAfter = 0): void
    {
        DB::table('ai_provider_attempts')->where('run_id', $id)->where('fence', $fence)->where('outcome', 'started')->update([
            'outcome' => $outcome, 'failure_code' => $code, 'provider_operation_id' => $this->operationId($providerId),
            'retry_after_seconds' => $retryAfter, 'completed_at' => DatabaseClock::now()]);
    }

    private function safeCode(string $value): string
    {
        return preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $value) === 1 ? $value : 'provider_failure';
    }

    private function operationId(?string $value): ?string
    {
        return $value !== null && preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $value) === 1 ? $value : null;
    }

    private function correlation(AIRun $run, OperationClaim $operation): string
    {
        return $operation->requestId ?? $run->requested_correlation_id;
    }
}
