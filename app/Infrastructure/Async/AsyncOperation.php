<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $kind
 * @property string $logical_key_hash
 * @property string $input_hash
 * @property array<string, string> $references
 * @property string|null $request_id
 * @property OperationState $state
 * @property int $attempts
 * @property int $max_attempts
 * @property int $fence
 * @property CarbonImmutable $next_attempt_at
 * @property CarbonImmutable|null $lease_expires_at
 * @property CarbonImmutable|null $last_dispatched_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $failure_code
 */
final class AsyncOperation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'references' => 'array',
            'state' => OperationState::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'fence' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'last_dispatched_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
