<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Queries;

use App\Modules\ProjectIntake\Contracts\IntakeActorNames;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\ProjectIntake\Models\RequestRevision;
use LogicException;
use stdClass;

/** Display projections over records already selected by the intake authorization scope. */
final readonly class ReadIntakeDisplay
{
    public function __construct(private IntakeActorNames $names) {}

    /** @param list<ProjectRequest> $records
     * @return array<string,array<string,mixed>>
     */
    public function summaries(array $records): array
    {
        $revisionIds = [];
        $identityIds = [];
        foreach ($records as $record) {
            if ($record->latest_revision_id !== null) {
                $revisionIds[] = $record->latest_revision_id;
            }
            foreach ([$record->assigned_staff_id, $record->customer_user_id] as $id) {
                if ($id !== null) {
                    $identityIds[] = $id;
                }
            }
        }
        $revisions = RequestRevision::query()->whereIn('id', $revisionIds)->get(['id', 'request_id', 'project_name', 'full_name'])->keyBy('id');
        $names = $this->names->lookup($identityIds);
        $views = [];
        foreach ($records as $record) {
            $revision = $record->latest_revision_id === null ? null : $revisions->get($record->latest_revision_id);
            if ($revision === null || $revision->request_id !== $record->id) {
                throw new LogicException('Staff display requires the canonical submitted revision.');
            }
            $views[$record->id] = [
                'customer_id' => $record->customer_id,
                'project_name' => $revision->project_name,
                'customer_display_name' => $record->customer_user_id === null ? $revision->full_name
                    : $this->identity($names, $record->customer_user_id)['display_name'],
                'provenance' => $record->guest_origin ? 'guest' : 'customer',
                'claimed' => $record->guest_origin && $record->customer_id !== null,
                'assigned_staff' => $this->staff($names, $record->assigned_staff_id),
            ];
        }

        return $views;
    }

    /** @param list<stdClass> $rows
     * @return array<string,array{id:?string,kind:string,display_name:?string}>
     */
    public function history(ProjectRequest $record, array $rows): array
    {
        $names = $this->names->lookup($this->ids($rows, ['actor_id']));
        $guestName = null;
        if ($record->guest_origin) {
            // The original guest remains a guest after claim and later customer amendments.
            $guestName = RequestRevision::query()->where('request_id', $record->id)->where('revision_number', 1)->value('full_name');
        }
        $actors = [];
        foreach ($rows as $row) {
            if (! is_string($row->id)) {
                throw new LogicException('Invalid history identity.');
            }
            if (is_string($row->actor_id)) {
                $actors[$row->id] = $this->identity($names, $row->actor_id);
            } elseif ($record->guest_origin && $row->from_state === 'draft' && $row->to_state === 'submitted') {
                $actors[$row->id] = ['id' => null, 'kind' => 'guest', 'display_name' => is_string($guestName) ? $guestName : null];
            } else {
                // PostgreSQL permits a null actor only for guest submission or automated proposal expiry.
                $actors[$row->id] = ['id' => null, 'kind' => 'system', 'display_name' => null];
            }
        }

        return $actors;
    }

    /** @param list<stdClass> $rows
     * @return array<string,array<string,mixed>>
     */
    public function assignments(array $rows): array
    {
        $names = $this->names->lookup($this->ids($rows, ['previous_staff_id', 'assigned_staff_id', 'assigned_by']));
        $views = [];
        foreach ($rows as $row) {
            if (! is_string($row->id) || ! is_string($row->assigned_staff_id) || ! is_string($row->assigned_by)
                || (! is_string($row->previous_staff_id) && $row->previous_staff_id !== null)) {
                throw new LogicException('Invalid assignment identity.');
            }
            $views[$row->id] = ['previous_staff' => $this->staff($names, $row->previous_staff_id),
                'assigned_staff' => $this->staff($names, $row->assigned_staff_id),
                'assigned_by_staff' => $this->staff($names, $row->assigned_by)];
        }

        return $views;
    }

    /** @param list<stdClass> $rows
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function ids(array $rows, array $fields): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                if (is_string($row->{$field})) {
                    $ids[] = $row->{$field};
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param array<string,array{id:string,kind:string,display_name:string}> $names
     * @return array{id:string,kind:string,display_name:string}
     */
    private function identity(array $names, string $id): array
    {
        // Referential integrity preserves historical identities, including disabled accounts.
        return $names[$id] ?? throw new LogicException('Referenced intake identity is missing.');
    }

    /** @param array<string,array{id:string,kind:string,display_name:string}> $names
     * @return array{id:string,display_name:string}|null
     */
    private function staff(array $names, ?string $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $identity = $this->identity($names, $id);

        return ['id' => $id, 'display_name' => $identity['display_name']];
    }
}
