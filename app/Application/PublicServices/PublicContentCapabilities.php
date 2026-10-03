<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Modules\Identity\Actions\WithAuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final readonly class PublicContentCapabilities
{
    public function __construct(private WithAuthorizedIdentity $identity, private SessionSecurity $sessions) {}

    public function read(Request $request): JsonResponse
    {
        return $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request): JsonResponse {
            if ($identity->kind !== 'staff' || ! $identity->emailPrerequisiteSatisfied || $request->session()->get('identity.mfa_verified') !== true) {
                throw new AuthorizationException;
            }
            if ($request->all() !== []) {
                throw ValidationException::withMessages(['input' => 'Unsupported fields.']);
            }
            $data = [];
            foreach (['portfolio.read', 'portfolio.manage', 'portfolio.publish', 'contact.read', 'contact.manage', 'contact.redact'] as $permission) {
                $data[str_replace('.', '_', $permission)] = $identity->allows($permission);
            }
            $data['recent_password_confirmation'] = $this->sessions->hasRecentPassword($request);

            return new JsonResponse(['data' => $data], headers: ['Cache-Control' => 'private, no-store']);
        });
    }
}
