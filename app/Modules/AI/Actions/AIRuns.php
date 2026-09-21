<?php

declare(strict_types=1);

namespace App\Modules\AI\Actions;

use App\Infrastructure\Async\OperationRecorder;
use App\Infrastructure\Clock\DatabaseClock;
use App\Modules\AI\Contracts\AICompletionNotifier;
use App\Modules\AI\Contracts\AIContextVerifier;
use App\Modules\AI\Data\AISource;
use App\Modules\AI\Models\AIRun;
use App\Modules\Audit\Actions\RecordAuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class AIRuns
{
    public function __construct(private OperationRecorder $operations, private RecordAuditEvent $audit,
        private AIContextVerifier $context, private AICompletionNotifier $notifier) {}

    public function create(AISource $source, string $purpose, string $idempotencyKey, string $correlation): AIRun
    {
        $this->validate($source, $purpose, $idempotencyKey);

        return DB::transaction(function () use ($source, $purpose, $idempotencyKey, $correlation): AIRun {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('holoul.ai.admission',0))");
            $key = hash('sha256', $idempotencyKey);
            $hash = hash('sha256', json_encode([$source, $purpose], JSON_THROW_ON_ERROR));
            $existing = AIRun::query()->where('actor_id', $source->actorId)->where('idempotency_hash', $key)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->input_hash, $hash)) {
                    throw new HttpException(409);
                }

                return $existing;
            }
            $day = DatabaseClock::now()->utc()->format('Y-m-d');
            DB::table('ai_budget_days')->insertOrIgnore(['day' => $day]);
            $budget = DB::table('ai_budget_days')->where('day', $day)->lockForUpdate()->first() ?? throw new HttpException(503);
            $reserve = Config::integer('ai.reserved_cost_microusd');
            $failure = $this->limitation($source->actorId, $day, $reserve, $budget->reserved_microusd, $budget->spent_microusd);
            $run = AIRun::query()->forceCreate(['actor_id' => $source->actorId, 'customer_id' => $source->customerId,
                'parent_type' => $source->parentType, 'parent_id' => $source->parentId, 'source_type' => $source->sourceType,
                'source_id' => $source->sourceId, 'source_version' => $source->sourceVersion, 'source_hash' => $source->sourceHash,
                'source_text' => $source->text, 'document_id' => $source->documentId, 'document_checksum' => $source->documentChecksum,
                'document_object_version' => $source->documentObjectVersion, 'taxonomy' => $source->taxonomy, 'purpose' => $purpose,
                'provider' => Config::string('ai.driver'), 'model' => Config::string('ai.model'), 'prompt_version' => Config::string('ai.prompt_version'),
                'schema_version' => Config::string('ai.schema_version'), 'state' => $failure === null ? 'pending' : 'unavailable',
                'lock_version' => 1, 'budget_day' => $day, 'reserved_cost_microusd' => $failure === null ? $reserve : 0,
                'reservation_state' => $failure === null ? 'reserved' : 'released', 'idempotency_hash' => $key, 'input_hash' => $hash,
                'requested_correlation_id' => $correlation, 'failure_code' => $failure,
                'completed_at' => $failure === null ? null : DatabaseClock::now()]);
            $this->context->verify($run);
            $this->event($run, 'requested', $source->actorId, $correlation);
            if ($failure !== null) {
                $this->event($run, str_contains($failure, 'budget') || str_contains($failure, 'limit') ? 'limit_rejected' : 'unavailable', $source->actorId, $correlation);
                $this->notifier->completed($run);

                return $run;
            }
            DB::table('ai_budget_days')->where('day', $day)->increment('reserved_microusd', $reserve);
            $operation = $this->operations->record('ai.generate', 'ai.generate:'.$run->id, ['ai_run_id' => $run->id], $correlation);
            $this->change($run, ['operation_id' => $operation->id]);

            return $run;
        }, 2);
    }

    public function cancel(AIRun $run, string $actorId, string $correlation): AIRun
    {
        $run = AIRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
        if ($run->actor_id !== $actorId) {
            throw new HttpException(404);
        }
        if (! in_array($run->state, ['pending', 'processing'], true)) {
            throw new HttpException(409);
        }
        $this->finish($run, 'cancelled', 'cancelled_by_requester', $run->state === 'processing', 0, $correlation);

        return $run;
    }

    public function dismiss(AIRun $run, string $actorId, string $correlation): AIRun
    {
        return $this->decide($run, $actorId, $correlation, 'dismissed');
    }

    /** Caller applied through the authorized owning module in this same outer transaction. */
    public function applyRecord(AIRun $run, string $actorId, string $correlation): AIRun
    {
        return $this->decide($run, $actorId, $correlation, 'applied');
    }

    /** @param array<string,mixed> $values */
    public function change(AIRun $run, array $values): void
    {
        $run->forceFill([...$values, 'lock_version' => $run->lock_version + 1])->save();
    }

    public function finish(AIRun $run, string $state, ?string $failure, bool $uncertain, int $actualCost, string $correlation): void
    {
        $terminal = in_array($run->state, ['succeeded', 'failed', 'unavailable', 'cancelled'], true);
        if ($terminal) {
            return;
        }
        if ($uncertain) {
            $this->change($run, ['state' => $state, 'failure_code' => $failure, 'reservation_state' => 'uncertain', 'completed_at' => DatabaseClock::now()]);
        } else {
            $this->settle($run, $actualCost);
            $this->change($run, ['state' => $state, 'failure_code' => $failure, 'completed_at' => DatabaseClock::now()]);
        }
        $this->event($run, $state === 'succeeded' ? 'completed' : $state, $run->actor_id, $correlation);
        $this->notifier->completed($run);
    }

    public function settle(AIRun $run, int $actualCost): void
    {
        if (! in_array($run->reservation_state, ['reserved', 'uncertain'], true)) {
            return;
        }
        if ($actualCost < 0 || $actualCost > $run->reserved_cost_microusd) {
            throw new \LogicException('Invalid bounded AI cost.');
        }
        DB::update('UPDATE ai_budget_days SET reserved_microusd=reserved_microusd-?, spent_microusd=spent_microusd+? WHERE day=?',
            [$run->reserved_cost_microusd, $actualCost, $run->budget_day]);
        $this->change($run, ['reservation_state' => 'settled', 'actual_cost_microusd' => $actualCost]);
    }

    public function event(AIRun $run, string $event, string $actorId, string $correlation): void
    {
        $this->audit->handle('ai.'.$event, 'ai.run', $run->id, $correlation, $actorId);
    }

    private function decide(AIRun $run, string $actorId, string $correlation, string $decision): AIRun
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('AI suggestion decisions require an owning outer transaction.');
        }
        $run = AIRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
        if ($run->actor_id !== $actorId || $run->state !== 'succeeded') {
            throw new HttpException(409);
        }
        $updated = DB::table('ai_suggestions')->where('run_id', $run->id)->where('state', 'pending')->update([
            'state' => $decision, 'decided_by' => $actorId, 'decided_at' => DatabaseClock::now()]);
        if ($updated !== 1) {
            throw new HttpException(409);
        }
        $this->change($run, []);
        $this->event($run, 'suggestion_'.$decision, $actorId, $correlation);

        return $run;
    }

    private function limitation(string $actor, string $day, int $reserve, mixed $reserved, mixed $spent): ?string
    {
        if (! Config::boolean('ai.enabled') || ! in_array(Config::string('ai.driver'), Config::array('ai.allowed_providers'), true)
            || ! in_array(Config::string('ai.model'), Config::array('ai.allowed_models'), true)) {
            return 'provider_unavailable';
        }
        if (! is_int($reserved) || ! is_int($spent) || $reserve < 0 || $reserve > Config::integer('ai.daily_budget_microusd')
            || $reserved > Config::integer('ai.daily_budget_microusd') - $reserve - $spent) {
            return 'daily_budget_exhausted';
        }
        $active = AIRun::query()->where(function (Builder $query): void {
            $query->whereIn('state', ['pending', 'processing'])->orWhere('reservation_state', 'uncertain');
        });
        if ((clone $active)->count() >= Config::integer('ai.concurrent_runs') || (clone $active)->where('actor_id', $actor)->count() >= Config::integer('ai.concurrent_runs_per_user')) {
            return 'concurrent_limit';
        }
        if (AIRun::query()->where('actor_id', $actor)->where('budget_day', $day)->count() >= Config::integer('ai.runs_per_user_per_day')) {
            return 'daily_user_limit';
        }

        return null;
    }

    private function validate(AISource $source, string $purpose, string $key): void
    {
        foreach ([$source->actorId, $source->customerId, $source->parentId, $source->sourceId] as $id) {
            if (! Str::isUuid($id, 7)) {
                throw new HttpException(422);
            }
        }
        if (! in_array($purpose, AISchema::PURPOSES, true) || ! in_array($source->parentType, ['request', 'project'], true)
            || ! in_array($source->sourceType, ['intake_draft', 'intake_revision', 'project_document'], true)
            || $source->sourceVersion < 1 || preg_match('/\A[a-f0-9]{64}\z/D', $source->sourceHash) !== 1
            || mb_strlen($source->text) > Config::integer('ai.maximum_input_characters') || count($source->taxonomy) > 100
            || preg_match('/\A[A-Za-z0-9_.:-]{16,128}\z/D', $key) !== 1) {
            throw new HttpException(422);
        }
        if (($source->sourceType === 'project_document' && $source->documentId !== $source->sourceId)
            || ($source->documentId !== null && ($source->documentChecksum === null
            || preg_match('/\A[a-f0-9]{64}\z/D', $source->documentChecksum) !== 1 || $source->documentObjectVersion === null
            || $source->documentObjectVersion === '' || strlen($source->documentObjectVersion) > 1024 || $source->text !== ''))) {
            throw new HttpException(422);
        }
    }
}
