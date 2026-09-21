<?php

declare(strict_types=1);

namespace App\Modules\AI\Actions;

use App\Modules\AI\Models\AIRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AIDecisionReceipts
{
    /** @param array<string,mixed> $input
     * @return array{key:string,hash:string}
     */
    public function fingerprint(string $operation, string $runId, ?string $etag, ?string $key, array $input): array
    {
        if (! in_array($operation, ['apply', 'dismiss', 'cancel'], true) || $key === null || preg_match('/\A[A-Za-z0-9_.:-]{16,128}\z/D', $key) !== 1) {
            throw new HttpException(422);
        }
        ksort($input);

        return ['key' => hash('sha256', $key), 'hash' => hash('sha256', json_encode([$operation, $runId, $etag, $input], JSON_THROW_ON_ERROR))];
    }

    /** @param array{key:string,hash:string} $fingerprint
     * @return ?array{id:string,state:string,version:int,suggestion_state:?string}
     */
    public function replay(string $actorId, string $operation, string $runId, array $fingerprint): ?array
    {
        $row = DB::table('ai_command_keys')->select('*')->selectRaw('expires_at<=clock_timestamp() AS expired')
            ->where('actor_id', $actorId)->where('operation', $operation)->where('key_hash', $fingerprint['key'])->first();
        if ($row === null) {
            return null;
        }
        if ($row->run_id !== $runId || $row->input_hash !== $fingerprint['hash'] || $row->expired === true) {
            throw new HttpException(409);
        }
        if (! is_string($row->result_state) || ! is_int($row->result_version) || ($row->suggestion_state !== null && ! is_string($row->suggestion_state))) {
            throw new \LogicException('Invalid AI receipt.');
        }

        return ['id' => $runId, 'state' => $row->result_state, 'version' => $row->result_version, 'suggestion_state' => $row->suggestion_state];
    }

    /** @param array{key:string,hash:string} $fingerprint
     * @return array{id:string,state:string,version:int,suggestion_state:?string}
     */
    public function record(string $actorId, string $operation, array $fingerprint, AIRun $run): array
    {
        $suggestion = DB::table('ai_suggestions')->where('run_id', $run->id)->value('state');
        if ($suggestion !== null && ! is_string($suggestion)) {
            throw new \LogicException('Invalid suggestion state.');
        }
        DB::table('ai_command_keys')->insert(['id' => (string) Str::uuid7(), 'actor_id' => $actorId, 'run_id' => $run->id,
            'operation' => $operation, 'key_hash' => $fingerprint['key'], 'input_hash' => $fingerprint['hash'],
            'result_state' => $run->state, 'result_version' => $run->lock_version, 'suggestion_state' => $suggestion]);

        return ['id' => $run->id, 'state' => $run->state, 'version' => $run->lock_version, 'suggestion_state' => $suggestion];
    }
}
