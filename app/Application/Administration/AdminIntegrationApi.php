<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Customers\Actions\ReadCustomerDirectory;
use App\Modules\Identity\Actions\ReadCustomerDirectoryIdentities;
use App\Modules\Identity\Actions\StaffEligibility;
use App\Modules\Identity\Actions\WithAuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Security\AuthLimiter;
use App\Modules\ProjectIntake\Actions\AssignRequest;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Queries\ReadIntake;
use App\Modules\Projects\Actions\ProjectRead;
use App\Modules\Projects\Actions\ProjectStore;
use App\Modules\Projects\Data\ProjectActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class AdminIntegrationApi
{
    public function __construct(private WithAuthorizedIdentity $identity, private ReadCustomerDirectory $customers,
        private ReadCustomerDirectoryIdentities $contacts, private ReadIntake $intake, private ProjectRead $projects,
        private IntakeStore $intakeStore, private AssignRequest $assignments, private ProjectStore $projectStore,
        private StaffEligibility $staff, private AuthLimiter $limiter, private RecordAuditEvent $audit) {}

    /** @param array<string,mixed> $input */
    public function handle(Request $request, string $operation, array $input): JsonResponse
    {
        return $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $operation, $input): JsonResponse {
            if ($identity->kind !== 'staff') {
                throw new AuthorizationException;
            }
            $this->limiter->consume([['key' => 'admin-integration:'.$identity->id, 'maximum' => 120, 'seconds' => 60]]);
            $intakeActor = new IntakeActor($identity->id, null, $identity->verifiedEmail, $identity->permissions);
            $projectActor = new ProjectActor($identity->id, null, $identity->verifiedEmail, false, $identity->permissions);
            if (str_starts_with($operation, 'customers.')) {
                $result = $this->directory($request, $operation, $input, $identity, $intakeActor, $projectActor);

                return new JsonResponse($result, headers: ['Cache-Control' => 'private, no-store']);
            }
            $role = is_string($input['role'] ?? null) ? $input['role'] : '';
            if ($operation === 'intake.eligible') {
                $id = $this->parameter($request, 'projectRequest');
                $record = $this->intakeStore->find($intakeActor, $id);
                $this->intakeStore->policy->staff($intakeActor, $record, 'intake.assign', false);
                $this->assignments->requireOpen($record);
                $query = $this->staff->query(['intake.read'], StaffEligibility::intakeActions());
                if ($record->assigned_staff_id !== null) {
                    $query->where('users.id', '<>', $record->assigned_staff_id);
                }
                $version = $record->lock_version;
                $context = ['type' => 'project_request', 'id' => $id, 'role' => null];
                $label = 'project_requests.assignee';
            } elseif ($operation === 'projects.eligible') {
                $id = $this->parameter($request, 'project');
                $record = $this->projectStore->find($projectActor, $id);
                $this->projectStore->staff($projectActor, $record, 'projects.team.manage', true);
                $this->projectStore->active($record);
                $query = $this->staff->query(StaffEligibility::projectPermissions($role))
                    ->whereNotIn('users.id', $this->projects->activeMemberIds($projectActor, $record));
                $version = $record->lock_version;
                $context = ['type' => 'project', 'id' => $id, 'role' => $role];
                $label = 'projects.team.'.$role;
            } else {
                throw new HttpException(404);
            }
            if (is_string($input['q'] ?? null)) {
                $query->where('users.full_name', 'ilike', '%'.addcslashes($input['q'], '\\%_').'%');
            }
            $page = $this->page($query, 'users.id', $input);
            $page['data'] = array_map(static fn (array $row): array => ['id' => $row['id'], 'display_name' => $row['full_name'], 'capability' => $label], $page['data']);
            $page['context'] = $context;

            return new JsonResponse($page, headers: ['Cache-Control' => 'private, no-store', 'ETag' => VersionPrecondition::etag($id, $version)]);
        });
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function directory(Request $request, string $operation, array $input, AuthorizedIdentity $actor, IntakeActor $intakeActor, ProjectActor $projectActor): array
    {
        $intake = $actor->allows('intake.read') ? $this->intake->customerDirectoryScope($intakeActor) : null;
        $projects = $actor->allows('projects.read') ? $this->projects->customerDirectoryScope($projectActor) : null;
        $query = $this->customers->query($actor, $this->contacts->query(), $intake, $projects);
        if ($operation === 'customers.list') {
            if (is_string($input['q'] ?? null)) {
                $literal = '%'.addcslashes($input['q'], '\\%_').'%';
                $query->where(function (Builder $search) use ($literal): void {
                    $search->where('identity.full_name', 'ilike', $literal)->orWhere('identity.email', 'ilike', $literal)
                        ->orWhere('identity.email_display', 'ilike', $literal)->orWhere('customers.phone_e164', 'like', $literal)
                        ->orWhere('customers.phone_display', 'like', $literal);
                });
            }
            if (isset($input['status'])) {
                $query->where('identity.enabled', $input['status'] === 'active');
            }
            if (isset($input['email_verified'])) {
                $query->whereNull('identity.email_verified_at', not: $input['email_verified'] === 'true');
            }
            $page = $this->page($query, 'customers.id', $input);
            $this->audit->handle('customers.directory_viewed', 'user', $actor->id, $request->attributes->getString('request_id'), $actor->id);

            return $page;
        }
        $id = $this->parameter($request, 'customer');
        if (! Str::isUuid($id, 7)) {
            throw new HttpException(404);
        }
        $customer = $query->where('customers.id', $id)->first() ?? throw new HttpException(404);
        $this->audit->handle('customers.staff_viewed', 'customer', $id, $request->attributes->getString('request_id'), $actor->id);
        if ($operation === 'customers.detail') {
            return ['data' => [...get_object_vars($customer), 'links' => [
                'project_requests' => '/api/v1/admin/customers/'.$id.'/project-requests',
                'projects' => '/api/v1/admin/customers/'.$id.'/projects']]];
        }
        $scope = $operation === 'customers.requests' ? $intake : $projects;
        if ($scope === null) {
            throw new AuthorizationException;
        }
        // One scoped projection query, regardless of page size; no per-row resource fetches.
        $rows = DB::query()->fromSub($scope->where('customer_id', $id), 'visible')
            ->select('id', 'reference', 'state')->selectRaw("to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS created_at");

        return $this->page($rows, 'id', $input);
    }

    /** @param array<string,mixed> $input
     * @return array{data:list<array<array-key,mixed>>,meta:array{next_after:mixed,per_page:int}}
     */
    private function page(Builder $query, string $idColumn, array $input): array
    {
        $limit = is_int($input['limit'] ?? null) ? $input['limit'] : 25;
        if (is_string($input['after'] ?? null)) {
            $query->where($idColumn, '>', $input['after']);
        }
        $rows = $query->orderBy($idColumn)->limit($limit + 1)->get();
        $page = array_values($rows->take($limit)->map(static fn (stdClass $row): array => get_object_vars($row))->all());

        return ['data' => $page, 'meta' => ['next_after' => $rows->count() > $limit ? $page[$limit - 1]['id'] : null, 'per_page' => $limit]];
    }

    private function parameter(Request $request, string $name): string
    {
        $value = $request->route($name);

        return is_string($value) ? $value : '';
    }
}
