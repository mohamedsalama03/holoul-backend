<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Actions;

use App\Modules\ProjectIntake\Contracts\CommercialContext;
use App\Modules\Proposals\Models\Proposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ProposalReceipts
{
    /** @param array<string,mixed> $input
     * @return array{key:string,hash:string}
     */
    public function fingerprint(CommercialContext $context, string $operation, string $id, ?string $etag, ?string $key, array $input): array
    {
        if ($key === null || preg_match('/\A[A-Za-z0-9_.:-]{16,128}\z/D', $key) !== 1) {
            throw new HttpException(422);
        }
        ksort($input);

        return ['key' => hash('sha256', $key), 'hash' => hash('sha256', json_encode([$context->requestId, $operation, $id, $etag, $input], JSON_THROW_ON_ERROR))];
    }

    /** @param array{key:string,hash:string} $fingerprint
     * @return ?array{id:string,state:string,version:int,request_version:int}
     */
    public function replay(CommercialContext $context, array $fingerprint): ?array
    {
        // Identity is locked before the request, serializing key reuse across requests.
        $row = DB::table('proposal_command_keys')->select('*')->selectRaw('expires_at <= clock_timestamp() AS expired')
            ->where('actor_id', $context->actorId)->where('key_hash', $fingerprint['key'])->first();
        if ($row === null) {
            return null;
        }
        if ($row->expired === true || $row->input_hash !== $fingerprint['hash'] || $row->request_id !== $context->requestId) {
            throw new HttpException(409);
        }
        if (! is_string($row->proposal_id) || ! is_string($row->result_state) || ! is_int($row->result_version) || ! is_int($row->request_version)) {
            throw new HttpException(500);
        }

        return ['id' => $row->proposal_id, 'state' => $row->result_state, 'version' => $row->result_version, 'request_version' => $row->request_version];
    }

    /** @param array{key:string,hash:string} $fingerprint
     * @return array{id:string,state:string,version:int,request_version:int}
     */
    public function record(CommercialContext $context, string $operation, array $fingerprint, Proposal $proposal, int $requestVersion): array
    {
        DB::table('proposal_command_keys')->insert(['id' => (string) Str::uuid7(), 'actor_id' => $context->actorId, 'key_hash' => $fingerprint['key'],
            'input_hash' => $fingerprint['hash'], 'request_id' => $context->requestId, 'operation' => $operation, 'proposal_id' => $proposal->id,
            'result_state' => $proposal->state, 'result_version' => $proposal->lock_version, 'request_version' => $requestVersion]);

        return ['id' => $proposal->id, 'state' => $proposal->state, 'version' => $proposal->lock_version, 'request_version' => $requestVersion];
    }
}
