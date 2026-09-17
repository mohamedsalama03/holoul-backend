<?php

declare(strict_types=1);

namespace App\Modules\Discovery\Actions;

use App\Infrastructure\Clock\DatabaseClock;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Discovery\Models\DiscoveryRevision;
use App\Modules\ProjectIntake\Contracts\CommercialContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ManageDiscovery
{
    public function __construct(private RecordAuditEvent $audit) {}

    /** @param array<string,mixed> $input */
    public function create(CommercialContext $context, array $input): DiscoveryRevision
    {
        $context->staff('discovery.manage');
        $context->requireState(['discovery']);
        $values = $this->content($input);
        $record = DB::table('discovery_records')->where('request_id', $context->requestId)->lockForUpdate()->first();
        if ($record === null) {
            $id = (string) Str::uuid7();
            DB::table('discovery_records')->insert(['id' => $id, 'request_id' => $context->requestId, 'customer_id' => $context->customerId]);
            $number = 1;
        } else {
            $id = is_string($record->id) ? $record->id : throw new HttpException(409);
            $number = is_int($record->latest_revision_number) ? $record->latest_revision_number + 1 : throw new HttpException(409);
            if (DiscoveryRevision::query()->where('discovery_id', $id)->where('state', '<>', 'completed')->exists()) {
                throw new HttpException(409);
            }
        }
        $revision = DiscoveryRevision::query()->forceCreate([...$values, 'discovery_id' => $id, 'request_id' => $context->requestId,
            'customer_id' => $context->customerId, 'revision_number' => $number, 'source_intake_revision_id' => $context->intakeRevisionId,
            'author_id' => $context->actorId, 'state' => 'draft', 'lock_version' => 1]);
        DB::table('discovery_records')->where('id', $id)->update(['latest_revision_number' => $number, 'current_revision_id' => $revision->id]);
        $this->event($context, $revision, 'created');

        return $revision;
    }

    /** @param array<string,mixed> $input */
    public function update(CommercialContext $context, string $id, array $input): DiscoveryRevision
    {
        $revision = $this->editable($context, $id);
        $revision->forceFill([...$this->content($input), 'lock_version' => $revision->lock_version + 1])->save();
        $this->event($context, $revision, 'updated');

        return $revision;
    }

    /** @param array<string,mixed> $input */
    public function requirements(CommercialContext $context, string $id, array $input): DiscoveryRevision
    {
        $revision = $this->editable($context, $id);
        Validator::make($input, [
            'requirements' => ['present', 'array', 'max:100'],
            'requirements.*' => ['required', 'array:title,description,category,priority,notes,status'],
            'requirements.*.title' => ['required', 'string', 'max:200'],
            'requirements.*.description' => ['required', 'string', 'max:10000'],
            'requirements.*.category' => ['required', 'string', 'in:functional,non_functional,constraint'],
            'requirements.*.priority' => ['required', 'string', 'in:must,should,could'],
            'requirements.*.notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'requirements.*.status' => ['required', 'string', 'in:proposed,confirmed,excluded'],
        ])->validate();
        $requirements = $input['requirements'];
        if (! is_array($requirements) || ! array_is_list($requirements)) {
            throw new HttpException(422);
        }
        DB::table('discovery_requirements')->where('revision_id', $id)->delete();
        foreach ($requirements as $index => $item) {
            if (! is_array($item)) {
                throw new HttpException(422);
            }
            $row = ['id' => (string) Str::uuid7(), 'revision_id' => $id, 'position' => $index + 1];
            foreach (['title', 'description', 'category', 'priority', 'notes', 'status'] as $field) {
                $value = $item[$field] ?? null;
                if ($field === 'notes' && $value === null) {
                    $value = '';
                }
                if (! is_string($value)) {
                    throw new HttpException(422);
                }
                $row[$field] = $value;
            }
            DB::table('discovery_requirements')->insert($row);
        }
        $revision->lock_version++;
        $revision->save();
        $this->event($context, $revision, 'requirements_changed');

        return $revision;
    }

    public function start(CommercialContext $context, string $id): DiscoveryRevision
    {
        $revision = $this->editable($context, $id);
        if ($revision->state !== 'draft') {
            throw new HttpException(409);
        }
        $revision->forceFill(['state' => 'in_progress', 'lock_version' => $revision->lock_version + 1])->save();
        $this->event($context, $revision, 'started');

        return $revision;
    }

    public function complete(CommercialContext $context, string $id): DiscoveryRevision
    {
        $context->staff('discovery.complete');
        $revision = $this->editable($context, $id);
        if ($revision->state !== 'in_progress'
            || ! DB::table('discovery_requirements')->where('revision_id', $id)->where('status', 'confirmed')->exists()
            || DB::table('discovery_requirements')->where('revision_id', $id)->where('status', 'proposed')->exists()) {
            throw new HttpException(409);
        }
        $time = DatabaseClock::now();
        $revision->forceFill(['state' => 'completed', 'lock_version' => $revision->lock_version + 1, 'completed_at' => $time])->save();
        DB::table('discovery_signoffs')->insert(['id' => (string) Str::uuid7(), 'revision_id' => $id, 'revision_version' => $revision->lock_version,
            'completed_by' => $context->actorId, 'completed_at' => $time]);
        $this->event($context, $revision, 'completed');

        return $revision;
    }

    public function find(CommercialContext $context, string $id): DiscoveryRevision
    {
        $context->staff('discovery.read');
        if (! Str::isUuid($id, 7)) {
            throw new HttpException(404);
        }

        return DiscoveryRevision::query()->where('request_id', $context->requestId)->whereKey($id)->lockForUpdate()->first() ?? throw new HttpException(404);
    }

    /** @return array<string,mixed> */
    public function detail(CommercialContext $context, string $id): array
    {
        $revision = $this->find($context, $id);
        $this->event($context, $revision, 'staff_viewed');

        return [...$revision->only(['id', 'request_id', 'revision_number', 'source_intake_revision_id', 'state', 'lock_version', 'summary', 'internal_notes', 'author_id', 'completed_at']),
            'requirements' => DB::table('discovery_requirements')->where('revision_id', $id)->orderBy('position')->get()->all(),
            'signoff' => DB::table('discovery_signoffs')->where('revision_id', $id)->first()];
    }

    /** @return array<string,mixed> */
    public function listing(CommercialContext $context, int $after, int $limit): array
    {
        $context->staff('discovery.read');
        $rows = DiscoveryRevision::query()->where('request_id', $context->requestId)->where('revision_number', '>', $after)->orderBy('revision_number')->limit($limit + 1)
            ->get(['id', 'revision_number', 'state', 'lock_version', 'completed_at']);

        return ['data' => $rows->take($limit)->toArray(), 'meta' => ['next_after' => $rows->count() > $limit ? $rows[$limit - 1]?->revision_number : null]];
    }

    private function editable(CommercialContext $context, string $id): DiscoveryRevision
    {
        $context->staff('discovery.manage');
        $context->requireState(['discovery']);
        $revision = $this->find($context, $id);
        if ($revision->state === 'completed') {
            throw new HttpException(409);
        }

        return $revision;
    }

    /** @param array<string,mixed> $input
     * @return array{summary:string,internal_notes:string}
     */
    private function content(array $input): array
    {
        $input['internal_notes'] ??= '';
        Validator::make($input, ['summary' => ['required', 'string', 'max:20000'], 'internal_notes' => ['present', 'string', 'max:10000']])->validate();
        if (! is_string($input['summary']) || ! is_string($input['internal_notes'])) {
            throw new HttpException(422);
        }

        return ['summary' => $input['summary'], 'internal_notes' => $input['internal_notes']];
    }

    private function event(CommercialContext $context, DiscoveryRevision $revision, string $event): void
    {
        $this->audit->handle('discovery.'.$event, 'discovery.revision', $revision->id, $context->correlationId, $context->actorId);
    }
}
