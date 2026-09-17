<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final readonly class OperationRecorder
{
    public function __construct(private OperationPublisher $publisher) {}

    /**
     * Call from the originating business transaction. The nested transaction
     * also permits standalone infrastructure operations. Only the outer commit
     * publishes; rollback removes both the intent and the callback.
     *
     * @param  array<string, string>  $references  Immutable UUIDv7 domain references only.
     */
    public function record(string $kind, string $logicalKey, array $references = [], ?string $requestId = null): AsyncOperation
    {
        if (preg_match('/\A[a-z][a-z0-9_.]{0,79}\z/D', $kind) !== 1 || $logicalKey === '' || strlen($logicalKey) > 255) {
            throw new InvalidArgumentException('Invalid operation identity.');
        }

        if ($requestId !== null && ! Str::isUuid($requestId)) {
            throw new InvalidArgumentException('Invalid request identifier.');
        }

        if (count($references) > 16) {
            throw new InvalidArgumentException('Too many operation references.');
        }

        foreach ($references as $name => $reference) {
            if (preg_match('/\A[a-z][a-z0-9_]{0,43}_id\z/D', $name) !== 1 || ! Str::isUuid($reference, 7)) {
                throw new InvalidArgumentException('Operation references must be named UUIDv7 identifiers.');
            }
        }

        ksort($references);
        $encodedReferences = json_encode((object) $references, JSON_THROW_ON_ERROR);

        if (strlen($encodedReferences) > 2048) {
            throw new InvalidArgumentException('Operation references exceed the size limit.');
        }

        return DB::transaction(function () use ($kind, $logicalKey, $encodedReferences, $requestId): AsyncOperation {
            $logicalKeyHash = hash('sha256', $logicalKey);
            $inputHash = hash('sha256', $encodedReferences);

            DB::table('async_operations')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'kind' => $kind,
                'logical_key_hash' => $logicalKeyHash,
                'input_hash' => $inputHash,
                'references' => $encodedReferences,
                'request_id' => $requestId,
                'state' => OperationState::Pending->value,
                'attempts' => 0,
                'max_attempts' => OperationPolicy::maxAttempts($kind),
                'fence' => 0,
                'next_attempt_at' => DB::raw('clock_timestamp()'),
                'created_at' => DB::raw('clock_timestamp()'),
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);

            $operation = AsyncOperation::query()
                ->where('kind', $kind)
                ->where('logical_key_hash', $logicalKeyHash)
                ->firstOrFail();

            if (! hash_equals($operation->input_hash, $inputHash)) {
                throw new LogicException('The operation identity was already used with different references.');
            }

            DB::afterCommit(fn () => $this->publisher->publish($operation->id));

            return $operation;
        });
    }
}
