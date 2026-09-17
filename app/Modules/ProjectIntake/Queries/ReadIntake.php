<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Queries;

use App\Infrastructure\Http\VersionPrecondition;
use App\Infrastructure\Money\Money;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Http\Resources\RequestRevisionResource;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\ProjectIntake\Models\RequestDraft;
use App\Modules\ProjectIntake\Models\RequestRevision;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final readonly class ReadIntake
{
    public function __construct(private IntakeStore $store) {}

    /** @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function listing(IntakeActor $actor, array $filters): array
    {
        $limit = is_int($filters['limit'] ?? null) ? $filters['limit'] : 25;
        $ascending = ($filters['sort'] ?? '-created_at') === 'created_at';
        $query = $this->store->policy->scope(ProjectRequest::query(), $actor);
        foreach (['state', 'reference', 'assigned_staff_id'] as $field) {
            if (is_string($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (is_string($filters['category_id'] ?? null)) {
            $category = $filters['category_id'];
            $query->where(function (Builder $scope) use ($category): void {
                $scope->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('request_revisions')->whereColumn('request_revisions.id', 'project_requests.latest_revision_id')->where('category_id', $category))
                    ->orWhere(fn (Builder $drafts) => $drafts->where('state', 'draft')->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('request_drafts')->whereColumn('request_drafts.request_id', 'project_requests.id')->where('category_id', $category)));
            });
        }
        if (is_string($filters['q'] ?? null)) {
            $text = $filters['q'];
            $query->where(function (Builder $scope) use ($text): void {
                $scope->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('request_revisions')->whereColumn('request_revisions.id', 'project_requests.latest_revision_id')
                    ->whereRaw("to_tsvector('simple',project_name||' '||project_description) @@ plainto_tsquery('simple',?)", [$text]))
                    ->orWhere(fn (Builder $drafts) => $drafts->where('state', 'draft')->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('request_drafts')->whereColumn('request_drafts.request_id', 'project_requests.id')
                        ->whereRaw("to_tsvector('simple',coalesce(project_name,'')||' '||coalesce(project_description,'')) @@ plainto_tsquery('simple',?)", [$text])));
            });
        }
        if (is_string($filters['cursor'] ?? null)) {
            [$at, $id] = $this->decodeCursor($filters['cursor']);
            $operator = $ascending ? '>' : '<';
            $query->where(function (Builder $q) use ($at, $id, $operator): void {
                $q->where('created_at', $operator, $at)->orWhere(fn (Builder $tie) => $tie->where('created_at', $at)->where('id', $operator, $id));
            });
        }
        $rows = $query->orderBy('created_at', $ascending ? 'asc' : 'desc')->orderBy('id', $ascending ? 'asc' : 'desc')->limit($limit + 1)
            ->get(['id', 'state', 'reference', 'lock_version', 'created_at', 'submitted_at', 'assigned_staff_id', 'latest_revision_number']);
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);
        $last = $page->last();

        return ['data' => $page->map(fn (ProjectRequest $row): array => $this->summary($row, $actor))->values()->all(),
            'meta' => ['next_cursor' => $hasMore && $last !== null ? $this->cursor($last) : null, 'per_page' => $limit]];
    }

    /** @return array<string,mixed> */
    public function detail(IntakeActor $actor, string $id, string $requestId, ?string $parentCustomer = null): array
    {
        $record = $this->store->find($actor, $id, false, $parentCustomer);
        if ($actor->customerId === null) {
            $this->store->event('staff_viewed', $record, $actor, $requestId);
        }
        $view = $this->summary($record, $actor);
        if ($actor->customerId !== null) {
            $view['draft'] = $this->draft($this->store->draft($record));
        }
        $latest = $record->latest_revision_id === null ? null : RequestRevision::query()->where('request_id', $id)->whereKey($record->latest_revision_id)->first();
        $view['latest_revision'] = $latest === null ? null : $this->revision($latest);
        $view['information_request_id'] = $record->information_request_id;

        return $view;
    }

    public function byReference(IntakeActor $actor, string $reference): string
    {
        if (preg_match('/\AREQ-[0-9]{4}-[0-9]{5,19}\z/D', $reference) !== 1) {
            throw new HttpException(404);
        }

        return $this->store->policy->scope(ProjectRequest::query(), $actor)->where('reference', $reference)->first(['id'])->id ?? throw new HttpException(404);
    }

    /** @return array<string,mixed> */
    public function revisionDetail(IntakeActor $actor, string $id, string $revisionId): array
    {
        $this->store->find($actor, $id);
        if (! Str::isUuid($revisionId, 7)) {
            throw new HttpException(404);
        }
        $revision = RequestRevision::query()->where('request_id', $id)->whereKey($revisionId)->first() ?? throw new HttpException(404);

        return $this->revision($revision);
    }

    /** @return array<string,mixed> */
    public function revisions(IntakeActor $actor, string $id, int $after = 0, int $limit = 25): array
    {
        $this->store->find($actor, $id);
        $rows = RequestRevision::query()->where('request_id', $id)->where('revision_number', '>', $after)->orderBy('revision_number')->limit($limit + 1)->get();
        $page = $rows->take($limit);
        $last = $page->last();

        $attachments = DB::table('intake_revision_documents')->whereIn('revision_id', $page->modelKeys())->pluck('document_id', 'revision_id');

        return ['data' => $page->map(fn (RequestRevision $row): array => (new RequestRevisionResource($row,
            is_string($attachments->get($row->id)) ? $attachments->get($row->id) : null))->toArray(request()))->values()->all(),
            'meta' => ['next_after' => $rows->count() > $limit ? $last?->revision_number : null]];
    }

    /** @return array<string,mixed> */
    public function information(IntakeActor $actor, string $id, ?string $after = null, int $limit = 25): array
    {
        $this->store->find($actor, $id);
        $query = DB::table('information_requests as q')->where('q.request_id', $id)
            ->leftJoin('information_responses as a', 'a.information_request_id', '=', 'q.id')
            ->leftJoin('information_resolutions as r', 'r.information_request_id', '=', 'q.id');
        if ($after !== null) {
            if (! Str::isUuid($after, 7)) {
                throw ValidationException::withMessages(['after' => 'Invalid cursor.']);
            }
            $query->where('q.id', '>', $after);
        }
        $rows = $query->orderBy('q.id')->limit($limit + 1)->get(['q.id', 'q.question', 'q.origin_state', 'q.created_at as requested_at',
            'a.response', 'a.created_at as responded_at', 'r.resolution', 'r.created_at as resolved_at']);
        $page = $rows->take($limit);

        return ['data' => $page->map(fn (object $row): array => $this->historyRow($row))->values()->all(),
            'meta' => ['next_after' => $rows->count() > $limit ? $page->last()?->id : null]];
    }

    /** @return array<string,mixed> */
    public function history(IntakeActor $actor, string $id, ?string $after = null, int $limit = 25): array
    {
        $record = $this->store->find($actor, $id);
        $query = DB::table('request_state_changes')->where('request_id', $record->id);
        if ($after !== null) {
            if (! Str::isUuid($after, 7)) {
                throw ValidationException::withMessages(['after' => 'Invalid cursor.']);
            }
            $query->where('id', '>', $after);
        }
        $columns = ['id', 'from_state', 'to_state', 'reason', 'created_at'];
        if ($actor->customerId === null) {
            $columns[] = 'actor_id';
        }
        $rows = $query->orderBy('id')->limit($limit + 1)->get($columns);
        $page = $rows->take($limit);

        return ['data' => $page->map(fn (object $row): array => $this->historyRow($row))->values()->all(),
            'meta' => ['next_after' => $rows->count() > $limit ? $page->last()?->id : null]];
    }

    /** @return array<string,mixed> */
    public function summary(ProjectRequest $record, IntakeActor $actor): array
    {
        $view = ['id' => $record->id, 'reference' => $record->reference, 'state' => $record->state->value,
            'version' => $record->lock_version, 'etag' => VersionPrecondition::etag($record->id, $record->lock_version),
            'latest_revision_number' => $record->latest_revision_number,
            'created_at' => $record->created_at->utc()->toISOString(), 'submitted_at' => $record->submitted_at?->utc()->toISOString()];
        if ($actor->customerId === null) {
            $view['assigned_staff_id'] = $record->assigned_staff_id;
        }

        return $view;
    }

    /** @return array<string,mixed> */
    public function assignments(IntakeActor $actor, string $id, ?string $after = null, int $limit = 25): array
    {
        $record = $this->store->find($actor, $id);
        $this->store->policy->staff($actor, $record, 'intake.read', false);
        $query = DB::table('request_assignments')->where('request_id', $id);
        if ($after !== null) {
            if (! Str::isUuid($after, 7)) {
                throw ValidationException::withMessages(['after' => 'Invalid cursor.']);
            }
            $query->where('id', '>', $after);
        }
        $rows = $query->orderBy('id')->limit($limit + 1)->get(['id', 'previous_staff_id', 'assigned_staff_id', 'assigned_by', 'created_at']);
        $page = $rows->take($limit);

        return ['data' => $page->map(fn (object $row): array => $this->historyRow($row))->values()->all(),
            'meta' => ['next_after' => $rows->count() > $limit ? $page->last()?->id : null]];
    }

    /** @return array<string,mixed> */
    public function revision(RequestRevision $row): array
    {
        $id = DB::table('intake_revision_documents')->where('revision_id', $row->id)->value('document_id');

        return (new RequestRevisionResource($row, is_string($id) ? $id : null))->toArray(request());
    }

    /** @return array<string,mixed> */
    private function draft(RequestDraft $draft): array
    {
        return ['document_id' => DB::table('intake_draft_documents')->where('draft_id', $draft->id)->value('document_id'),
            'is_open' => $draft->is_open, 'base_revision_number' => $draft->base_revision_number,
            'category_id' => $draft->category_id, 'subcategory_id' => $draft->subcategory_id,
            'project_name' => $draft->project_name, 'project_description' => $draft->project_description,
            'budget_unknown' => $draft->budget_unknown, 'estimated_budget' => $this->amount($draft->budget_minor, $draft->currency), 'currency' => $draft->currency];
    }

    private function amount(?int $minor, ?string $currency): ?string
    {
        return $minor !== null && $currency !== null ? Money::fromMinorUnits($minor, $currency)->decimal() : null;
    }

    /** @return array<string,mixed> */
    private function historyRow(object $row): array
    {
        $values = [];
        foreach (get_object_vars($row) as $key => $value) {
            if (! is_string($key)) {
                throw new \LogicException('Unexpected record field.');
            }
            $values[$key] = $value;
            if (str_ends_with($key, '_at') && is_string($value)) {
                $values[$key] = CarbonImmutable::parse($value)->utc()->toISOString();
            }
        }

        return $values;
    }

    private function cursor(ProjectRequest $row): string
    {
        return rtrim(strtr(base64_encode(json_encode(['at' => $row->created_at->utc()->format('Y-m-d\TH:i:s.u\Z'), 'id' => $row->id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{string,string} */
    private function decodeCursor(string $value): array
    {
        try {
            $decoded = base64_decode(strtr($value, '-_', '+/'), true);
            $data = is_string($decoded) ? json_decode($decoded, true, 4, JSON_THROW_ON_ERROR) : null;
            if (! is_array($data) || count($data) !== 2 || ! is_string($data['at'] ?? null) || ! is_string($data['id'] ?? null)
                || ! Str::isUuid($data['id'], 7) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D', $data['at']) !== 1) {
                throw new \InvalidArgumentException;
            }
            $at = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $data['at'], 'UTC');
            if ($at === null || $at->format('Y-m-d\TH:i:s.u\Z') !== $data['at']) {
                throw new \InvalidArgumentException;
            }

            return [$at->format('Y-m-d H:i:s.uP'), $data['id']];
        } catch (Throwable) {
            throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
        }
    }
}
