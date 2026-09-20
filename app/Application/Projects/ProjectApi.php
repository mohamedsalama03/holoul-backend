<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Customers\Contracts\CustomerContactReader;
use App\Modules\Identity\Actions\WithAuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Security\AuthLimiter;
use App\Modules\Identity\Security\SessionSecurity;
use App\Modules\Projects\Data\ProjectActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class ProjectApi
{
    public function __construct(private WithAuthorizedIdentity $identity, private CustomerContactReader $contacts,
        private SessionSecurity $sessions, private AuthLimiter $limiter, private ProjectWorkflow $workflow, private ConvertRequest $convert) {}

    /** @param array<string,mixed> $input */
    public function handle(Request $request, string $operation, array $input): JsonResponse
    {
        return $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $operation, $input): JsonResponse {
            $staff = $request->is('api/v1/admin/*');
            if (($staff && $identity->kind !== 'staff') || (! $staff && $identity->kind !== 'customer')) {
                throw new AuthorizationException;
            }
            $contact = $identity->kind === 'customer' ? $this->contacts->currentForIdentity($identity->id) : null;
            if ($identity->kind === 'customer' && $contact === null) {
                throw new AuthorizationException;
            }
            $recent = $operation === 'project.confirm';
            if ($recent) {
                $this->sessions->requireRecentPassword($request);
            }
            $this->limiter->consume([['key' => 'projects:'.$identity->id, 'maximum' => 120, 'seconds' => 60]]);
            $actor = new ProjectActor($identity->id, $contact?->customerId, $identity->verifiedEmail, $recent, $identity->permissions);
            $correlation = $request->attributes->get('request_id');
            $correlation = is_string($correlation) ? $correlation : (string) Str::uuid7();
            $projectId = $this->parameter($request, 'project');
            if ($operation === 'project.convert') {
                $data = $this->convert->handle($actor, $this->parameter($request, 'projectRequest'), $request->header('If-Match'), $request->header('Idempotency-Key'), $correlation);
                $projectId = $data['project_id'];
                $result = ['data' => $data, 'project_version' => $data['version']];
            } else {
                $result = $this->workflow->handle($actor, $projectId, $this->parameter($request, 'member') ?: $this->parameter($request, 'milestone'), $operation,
                    $request->header('If-Match'), $request->header('Idempotency-Key'), $input, $correlation);
            }
            $headers = ['Cache-Control' => 'private, no-store'];
            if (isset($result['project_version'])) {
                if (! is_int($result['project_version'])) {
                    throw new \LogicException('Invalid Project version.');
                }
                $headers['ETag'] = VersionPrecondition::etag($projectId, $result['project_version']);
                unset($result['project_version']);
            }
            $created = in_array($operation, ['project.convert', 'project.team.add', 'project.milestone.create', 'project.update.publish'], true);
            if ($created) {
                $data = $result['data'] ?? null;
                $id = is_array($data) ? ($data['id'] ?? null) : null;
                if (! is_string($id) || ! Str::isUuid($id, 7)) {
                    throw new \LogicException('Invalid created resource.');
                }
                $headers['Location'] = $operation === 'project.convert' ? '/api/v1/admin/projects/'.$id : '/api/v1/admin/projects/'.$projectId;
            }

            return new JsonResponse($result, $created ? 201 : 200, $headers);
        });
    }

    private function parameter(Request $request, string $name): string
    {
        $value = $request->route($name);

        return is_string($value) ? $value : '';
    }
}
