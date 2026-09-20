<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class PublishProjectUpdate
{
    public function __construct(private ProjectStore $store) {}

    /** @param array<string,mixed> $input */
    public function handle(ProjectActor $actor, Project $project, array $input, string $correlation): string
    {
        $this->store->staff($actor, $project, 'projects.updates.publish');
        $this->store->active($project);
        Validator::make($input, ['content' => ['required', 'string', 'max:10000']])->validate();
        $content = $input['content'];
        if (! is_string($content) || trim($content) === '') {
            throw new HttpException(422);
        }
        $this->store->changed($project);
        $id = (string) Str::uuid7();
        DB::table('project_updates')->insert(['id' => $id, 'project_id' => $project->id, 'author_id' => $actor->id,
            'content' => $content, 'entity_version' => $project->lock_version]);
        $this->store->event($project, 'update_published', $actor, $correlation);

        return $id;
    }
}
