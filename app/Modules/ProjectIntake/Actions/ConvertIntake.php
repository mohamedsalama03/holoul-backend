<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ConvertIntake
{
    public function __construct(private IntakeStore $store) {}

    public function name(ProjectRequest $request): string
    {
        $value = DB::table('request_revisions')->where('id', $request->latest_revision_id)->where('request_id', $request->id)->value('project_name');

        return is_string($value) ? $value : throw new HttpException(409);
    }

    public function handle(IntakeActor $actor, ProjectRequest $request, string $correlation): void
    {
        if (DB::transactionLevel() === 0 || $request->state !== RequestState::Approved || $actor->customerId !== null) {
            throw new HttpException(409);
        }
        $this->store->policy->staff($actor, $request, 'projects.convert');
        DB::table('request_state_changes')->insert(['id' => (string) Str::uuid7(), 'request_id' => $request->id, 'from_state' => 'approved',
            'to_state' => 'converted', 'actor_id' => $actor->id, 'entity_version' => $request->lock_version + 1, 'correlation_id' => $correlation]);
        $request->state = RequestState::Converted;
        $this->store->changed($request);
        $this->store->event('converted', $request, $actor, $correlation);
    }
}
