<?php

declare(strict_types=1);

namespace App\Modules\Audit\Queries;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Restricted safe columns only; no business joins or content fields. */
final class InvestigateAudit
{
    /** @param array<string,string> $filters
     * @return array{data:list<array<string,mixed>>,meta:array{next_cursor:?string,limit:int}}
     */
    public function read(string $from, string $until, array $filters, int $limit = 25, ?string $cursor = null): array
    {
        if ($limit < 1 || $limit > 100 || array_diff(array_keys($filters), ['event_type', 'actor_id', 'subject_type', 'subject_id', 'request_id']) !== []) {
            throw new HttpException(422);
        }
        ksort($filters);
        $scope = hash('sha256', json_encode([$from, $until, $filters], JSON_THROW_ON_ERROR));
        $query = DB::table('audit_events')->select(['id', 'actor_id', 'event_type', 'subject_type', 'subject_id', 'request_id'])
            ->selectRaw("to_char(occurred_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS occurred_at")
            ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $until);
        foreach ($filters as $column => $value) {
            $valid = in_array($column, ['event_type', 'subject_type'], true)
                ? strlen($value) <= ($column === 'event_type' ? 96 : 64) && preg_match('/\A[a-z][a-z0-9]*(?:[._][a-z0-9]+)*\z/D', $value) === 1
                : Str::isUuid($value);
            if (! $valid) {
                throw new HttpException(422);
            }
            $query->where($column, $value);
        }
        if ($cursor !== null) {
            [$time, $id] = $this->decode($cursor, $scope);
            $query->where(function (Builder $page) use ($time, $id): void {
                $page->where('occurred_at', '<', $time)->orWhere(function (Builder $tie) use ($time, $id): void {
                    $tie->where('occurred_at', $time)->where('id', '<', $id);
                });
            });
        }
        $rows = $query->orderByDesc('audit_events.occurred_at')->orderByDesc('id')->limit($limit + 1)->get();
        $page = $rows->take($limit);
        $next = null;
        if ($rows->count() > $limit) {
            $last = $page->last();
            if ($last === null || ! is_string($last->occurred_at) || ! is_string($last->id)) {
                throw new \LogicException('Invalid audit cursor row.');
            }
            $next = Crypt::encryptString(json_encode(['scope' => $scope, 'time' => $last->occurred_at, 'id' => $last->id], JSON_THROW_ON_ERROR));
        }

        $data = [];
        foreach ($page as $row) {
            $data[] = ['id' => $row->id, 'actor_id' => $row->actor_id, 'event_type' => $row->event_type,
                'subject_type' => $row->subject_type, 'subject_id' => $row->subject_id, 'request_id' => $row->request_id, 'occurred_at' => $row->occurred_at];
        }

        return ['data' => $data,
            'meta' => ['next_cursor' => $next, 'limit' => $limit]];
    }

    /** @return array{string,string} */
    private function decode(string $cursor, string $scope): array
    {
        if (strlen($cursor) > 2048) {
            throw new HttpException(422);
        }
        try {
            $value = json_decode(Crypt::decryptString($cursor), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new HttpException(422);
        }
        if (! is_array($value) || count($value) !== 3 || ($value['scope'] ?? null) !== $scope
            || ! is_string($value['id'] ?? null) || ! Str::isUuid($value['id'], 7)
            || ! is_string($value['time'] ?? null) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/D', $value['time']) !== 1) {
            throw new HttpException(422);
        }

        return [$value['time'], $value['id']];
    }
}
