<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Customers\Contracts\CustomerContactReader;
use App\Modules\Identity\Actions\WithAuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Security\AuthLimiter;
use App\Modules\Identity\Security\SessionSecurity;
use App\Modules\ProjectIntake\Data\IntakeActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class CommercialApi
{
    public function __construct(private WithAuthorizedIdentity $identity, private CustomerContactReader $contacts,
        private SessionSecurity $sessions, private AuthLimiter $limiter, private CommercialWorkflow $workflow) {}

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
            $decision = in_array($operation, ['proposal.accept', 'proposal.decline', 'proposal.rescind'], true);
            if ($decision) {
                $this->sessions->requireRecentPassword($request);
            }
            $this->limiter->consume([['key' => 'commercial:'.$identity->id, 'maximum' => 120, 'seconds' => 60]]);
            $actor = new IntakeActor($identity->id, $contact?->customerId, $identity->verifiedEmail, $identity->permissions);
            $correlation = $request->attributes->get('request_id');
            $requestId = $this->parameter($request, 'projectRequest');
            if ($operation === 'proposal.remove_document') {
                $input['document_id'] = $this->parameter($request, 'document');
            }
            $result = $this->workflow->handle($actor, $decision, $requestId, $this->parameter($request, 'revision') ?: $this->parameter($request, 'proposal'),
                $operation, $request->header('If-Match'), $request->header('Idempotency-Key'), $input, is_string($correlation) ? $correlation : (string) Str::uuid7());
            $version = $result['request_version'];
            if (! is_int($version)) {
                throw new \LogicException('Invalid request version.');
            }
            unset($result['request_version']);

            $created = str_ends_with($operation, '.create');
            $headers = ['ETag' => VersionPrecondition::etag($requestId, $version), 'Cache-Control' => 'private, no-store'];
            if ($created) {
                $data = $result['data'] ?? null;
                $id = is_array($data) ? ($data['id'] ?? null) : null;
                if (! is_string($id) || ! Str::isUuid($id, 7)) {
                    throw new \LogicException('Invalid created resource.');
                }
                $headers['Location'] = $request->getPathInfo().'/'.$id;
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
