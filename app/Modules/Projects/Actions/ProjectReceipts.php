<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ProjectReceipts
{
    /** @param array<string,mixed> $input
     * @return array{key:string,hash:string}
     */
    public function fingerprint(string $operation, string $target, string $child, ?string $etag, ?string $key, array $input): array
    {
        if ($key === null || preg_match('/\A[A-Za-z0-9_.:-]{16,128}\z/D', $key) !== 1) {
            throw new HttpException(422);
        }

        return ['key' => hash('sha256', $key), 'hash' => hash('sha256', json_encode([$operation, $target, $child, $etag, $this->canonical($input)], JSON_THROW_ON_ERROR))];
    }

    /** @param array{key:string,hash:string} $fingerprint
     * @return ?array{id:string,project_id:string,reference:string,state:string,version:int}
     */
    public function replay(ProjectActor $actor, string $operation, string $target, array $fingerprint): ?array
    {
        // The HTTP coordinator locks Identity before any parent, serializing
        // actor/operation key reuse across different Projects and requests.
        $row = DB::table('project_command_keys')->select('project_command_keys.*', 'projects.reference')->selectRaw('expires_at<=clock_timestamp() AS expired')
            ->join('projects', 'projects.id', '=', 'project_command_keys.project_id')->where('actor_id', $actor->id)->where('operation', $operation)
            ->where('key_hash', $fingerprint['key'])->first();
        if ($row === null) {
            return null;
        }
        if ($row->expired === true || $row->input_hash !== $fingerprint['hash'] || $row->target_id !== $target) {
            throw new HttpException(409);
        }
        if (! is_string($row->result_id) || ! is_string($row->project_id) || ! is_string($row->reference) || ! is_string($row->result_state) || ! is_int($row->result_version)) {
            throw new \LogicException('Invalid project command receipt.');
        }

        return ['id' => $row->result_id, 'project_id' => $row->project_id, 'reference' => $row->reference, 'state' => $row->result_state, 'version' => $row->result_version];
    }

    /** @param array{key:string,hash:string} $fingerprint
     * @return array{id:string,project_id:string,reference:string,state:string,version:int}
     */
    public function record(ProjectActor $actor, string $operation, string $target, array $fingerprint, Project $project, string $resultId): array
    {
        DB::table('project_command_keys')->insert(['id' => (string) Str::uuid7(), 'actor_id' => $actor->id, 'operation' => $operation,
            'target_id' => $target, 'key_hash' => $fingerprint['key'], 'input_hash' => $fingerprint['hash'], 'project_id' => $project->id,
            'result_id' => $resultId, 'result_state' => $project->state, 'result_version' => $project->lock_version]);

        return ['id' => $resultId, 'project_id' => $project->id, 'reference' => $project->reference, 'state' => $project->state, 'version' => $project->lock_version];
    }

    /** @param array<array-key,mixed> $value
     * @return array<array-key,mixed>
     */
    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }
}
