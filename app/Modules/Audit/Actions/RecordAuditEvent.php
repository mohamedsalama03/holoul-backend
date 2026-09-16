<?php

declare(strict_types=1);

namespace App\Modules\Audit\Actions;

use App\Modules\Audit\Data\SafeAuditMetadata;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RecordAuditEvent
{
    /**
     * Types are server-authored taxonomy codes, never client/provider text.
     * The insertion participates in the caller's transaction and never suppresses failures.
     */
    public function handle(
        string $eventType,
        string $subjectType,
        string $subjectId,
        string $requestId,
        ?string $actorId = null,
        ?SafeAuditMetadata $metadata = null,
    ): string {
        $this->validateCode($eventType, 96);
        $this->validateCode($subjectType, 64);

        foreach ([$subjectId, $requestId, $actorId] as $reference) {
            if ($reference !== null && ! Str::isUuid($reference)) {
                throw new InvalidArgumentException('Audit references must be UUIDs.');
            }
        }

        $id = Str::uuid7()->toString();

        DB::table('audit_events')->insert([
            'id' => $id,
            'actor_id' => $actorId,
            'event_type' => $eventType,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'request_id' => $requestId,
            'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.uP'),
            'metadata' => ($metadata ?? new SafeAuditMetadata)->toJson(),
        ]);

        return $id;
    }

    private function validateCode(string $value, int $maximumLength): void
    {
        if (strlen($value) > $maximumLength || preg_match('/\A[a-z][a-z0-9]*(?:[._][a-z0-9]+)*\z/', $value) !== 1) {
            throw new InvalidArgumentException('Audit types must be bounded machine codes.');
        }
    }
}
