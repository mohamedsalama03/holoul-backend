<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\AI\Actions\AIDecisionReceipts;
use App\Modules\AI\Actions\AIRuns;
use App\Modules\AI\Models\AIRun;
use App\Modules\Documents\Exceptions\DocumentSourceUnavailable;
use App\Modules\Identity\Actions\WithAuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Security\AuthLimiter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class AIApi
{
    public function __construct(private WithAuthorizedIdentity $identities, private AISources $sources,
        private AIRuns $runs, private AIDecisionReceipts $receipts, private ApplyAISuggestion $apply, private AuthLimiter $limiter) {}

    /** @param array<string,mixed> $input */
    public function handle(Request $request, string $operation, array $input): JsonResponse
    {
        return $this->identities->handle($request, function (AuthorizedIdentity $identity) use ($request, $operation, $input): JsonResponse {
            $buckets = [['key' => 'ai:'.$identity->id, 'maximum' => 120, 'seconds' => 60]];
            if ($operation === 'create') {
                $buckets[] = ['key' => 'ai:create:'.$identity->id, 'maximum' => 20, 'seconds' => 60];
            }
            $this->limiter->consume($buckets);
            $correlation = $request->attributes->get('request_id');
            $correlation = is_string($correlation) ? $correlation : (string) Str::uuid7();
            if (in_array($operation, ['create', 'list'], true)) {
                $parentType = $this->text($input, 'parent_type');
                $parentId = $this->text($input, 'parent_id');
                $parent = $this->sources->access($identity, $parentType, $parentId);
                if ($operation === 'list') {
                    $query = AIRun::query()->where('actor_id', $identity->id)->where('parent_type', $parentType)->where('parent_id', $parentId);
                    if (is_string($input['after'] ?? null)) {
                        $query->where('id', '>', $input['after']);
                    }
                    $limit = is_int($input['limit'] ?? null) ? $input['limit'] : 25;
                    $rows = $query->orderBy('id')->limit($limit + 1)->get();

                    return new JsonResponse(['data' => $rows->take($limit)->map(fn (AIRun $run): array => $this->summary($run))->all(),
                        'meta' => ['next_after' => $rows->count() > $limit ? $rows[$limit - 1]?->id : null]], headers: ['Cache-Control' => 'private, no-store']);
                }
                if (($input['consent'] ?? null) !== true) {
                    throw new HttpException(422);
                }
                $key = $request->header('Idempotency-Key');
                if (! is_string($key) || preg_match('/\A[A-Za-z0-9_.:-]{16,128}\z/D', $key) !== 1) {
                    throw new HttpException(422);
                }
                $purpose = $this->text($input, 'purpose');
                $documentId = is_string($input['document_id'] ?? null) ? $input['document_id'] : null;
                // Revalidate current actor/parent access above, then replay the original immutable request.
                $existing = AIRun::query()->where('actor_id', $identity->id)->where('idempotency_hash', hash('sha256', $key))->first();
                if ($existing !== null) {
                    if ($existing->parent_type !== $parentType || $existing->parent_id !== $parentId || $existing->purpose !== $purpose
                        || $existing->document_id !== $documentId || $request->header('If-Match') !== VersionPrecondition::etag($parentId, $existing->source_version)) {
                        throw new HttpException(409);
                    }

                    return $this->response($identity, $existing, 202);
                }
                VersionPrecondition::require($request->header('If-Match'), $parentId, $parent->lock_version);
                try {
                    $source = $this->sources->capture($identity, $parentType, $parentId, $purpose,
                        $documentId, $correlation);
                } catch (DocumentSourceUnavailable) {
                    throw new HttpException(409);
                }
                $run = $this->runs->create($source, $purpose, $key, $correlation);

                return $this->response($identity, $run, 202);
            }
            $id = $request->route('aiRun');
            if (! is_string($id) || ! Str::isUuid($id, 7)) {
                throw new HttpException(404);
            }
            $run = AIRun::query()->whereKey($id)->where('actor_id', $identity->id)->first() ?? throw new HttpException(404);
            $this->sources->access($identity, $run->parent_type, $run->parent_id);
            $run = AIRun::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($operation === 'detail') {
                return $this->response($identity, $run);
            }
            if (! in_array($operation, ['apply', 'dismiss', 'cancel'], true)) {
                throw new HttpException(404);
            }
            if ($operation === 'apply' && (! $identity->allows($identity->kind === 'customer' ? 'ai.self.apply' : 'ai.apply')
                || ($identity->kind === 'staff' && ! $identity->allows('discovery.manage')))) {
                throw new AuthorizationException;
            }
            $fingerprint = $this->receipts->fingerprint($operation, $id, $request->header('If-Match'), $request->header('Idempotency-Key'), $input);
            $replay = $this->receipts->replay($identity->id, $operation, $id, $fingerprint);
            if ($replay !== null) {
                return new JsonResponse(['data' => $replay], headers: ['Cache-Control' => 'private, no-store', 'ETag' => VersionPrecondition::etag($id, $replay['version'])]);
            }
            VersionPrecondition::require($request->header('If-Match'), $id, $run->lock_version);
            if ($operation === 'apply') {
                $suggestion = DB::table('ai_suggestions')->where('run_id', $id)->where('state', 'pending')->first();
                if ($run->state !== 'succeeded' || $suggestion === null || ! is_string($suggestion->output)) {
                    throw new HttpException(409);
                }
                $output = json_decode($suggestion->output, true, 16, JSON_THROW_ON_ERROR);
                if (! is_array($output)) {
                    throw new HttpException(409);
                }
                /** @var array<string,mixed> $output */
                $this->apply->handle($identity, $run, $output, is_string($input['discovery_revision_id'] ?? null) ? $input['discovery_revision_id'] : null, $correlation);
            }
            $run = match ($operation) {
                'apply' => $this->runs->applyRecord($run, $identity->id, $correlation),
                'dismiss' => $this->runs->dismiss($run, $identity->id, $correlation),
                'cancel' => $this->runs->cancel($run, $identity->id, $correlation),
            };
            $result = $this->receipts->record($identity->id, $operation, $fingerprint, $run);

            return new JsonResponse(['data' => $result], headers: ['Cache-Control' => 'private, no-store', 'ETag' => VersionPrecondition::etag($id, $run->lock_version)]);
        });
    }

    private function response(AuthorizedIdentity $identity, AIRun $run, int $status = 200): JsonResponse
    {
        $this->sources->authorizeHistory($identity, $run);
        $suggestion = DB::table('ai_suggestions')->where('run_id', $run->id)->first();
        $output = $suggestion !== null && is_string($suggestion->output) ? json_decode($suggestion->output, true, 16, JSON_THROW_ON_ERROR) : null;

        return new JsonResponse(['data' => [...$this->summary($run),
            'suggestion' => $suggestion === null ? null : ['state' => $suggestion->state, 'output' => $output]]], $status,
            ['Cache-Control' => 'private, no-store', 'ETag' => VersionPrecondition::etag($run->id, $run->lock_version)]);
    }

    /** @return array<string,mixed> */
    private function summary(AIRun $run): array
    {
        return [...$run->only(['id', 'parent_type', 'parent_id', 'source_type', 'source_id', 'source_version', 'source_hash', 'document_id',
            'document_checksum', 'purpose', 'provider', 'model', 'state', 'failure_code', 'created_at', 'started_at', 'completed_at',
            'input_tokens', 'output_tokens', 'actual_cost_microusd']), 'version' => $run->lock_version];
    }

    /** @param array<string,mixed> $input */
    private function text(array $input, string $key): string
    {
        return is_string($input[$key] ?? null) ? $input[$key] : throw new HttpException(422);
    }
}
