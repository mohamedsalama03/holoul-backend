<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Http\Requests\ContactRequest;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Contact\Actions\ContactInbox;
use App\Modules\Contact\Actions\ReceiveContact;
use App\Modules\Contact\Data\ContactActor;
use App\Modules\Contact\Data\ContactSubmission;
use App\Modules\Customers\Data\InternationalPhone;
use App\Modules\Identity\Actions\WithAuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Security\AuthLimiter;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;

final readonly class ContactApi
{
    public function __construct(private ReceiveContact $receive, private ContactInbox $inbox, private WithAuthorizedIdentity $identity,
        private AuthLimiter $limiter, private SessionSecurity $sessions) {}

    public function handle(ContactRequest $request): JsonResponse
    {
        $requestId = $request->attributes->getString('request_id');
        if ($request->operation() === 'create') {
            $email = $request->string('email')->toString();
            $this->limiter->consume([
                ['key' => 'contact:ip:'.$request->ip(), 'maximum' => 5, 'seconds' => 3600],
                ['key' => 'contact:email:'.hash_hmac('sha256', $email, Config::string('app.key')), 'maximum' => 3, 'seconds' => 3600],
                ['key' => 'contact:global', 'maximum' => 100, 'seconds' => 3600],
            ]);
            $submission = new ContactSubmission($request->string('full_name')->toString(), $email,
                InternationalPhone::parse($request->string('phone')->toString())->e164,
                is_string($request->input('company')) ? $request->string('company')->toString() : null, $request->string('message')->toString());

            return new JsonResponse(['data' => $this->receive->handle($submission, $request->header('Idempotency-Key', ''), $requestId)], 201, ['Cache-Control' => 'no-store']);
        }

        return $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $requestId): JsonResponse {
            if ($identity->kind !== 'staff' || ! $identity->verifiedEmail || $request->session()->get('identity.mfa_verified') !== true) {
                throw new AuthorizationException;
            }
            $actor = new ContactActor($identity->id, $identity->permissions, $this->sessions->hasRecentPassword($request));
            $this->limiter->consume([['key' => 'contact:admin:'.$identity->id, 'maximum' => 120, 'seconds' => 60]]);
            if ($request->operation() === 'list') {
                $result = $this->inbox->page($actor, $request->integer('limit', 25),
                    is_string($request->input('status')) ? $request->string('status')->toString() : null,
                    is_string($request->input('cursor')) ? $request->string('cursor')->toString() : null, $requestId);

                return new JsonResponse($result, headers: ['Cache-Control' => 'private, no-store']);
            }
            $id = $request->route('message');
            $id = is_string($id) ? $id : '';
            $message = $request->operation() === 'detail' ? $this->inbox->find($actor, $id)
                : $this->inbox->change($actor, $id, $request->header('If-Match'), $request->operation() === 'redact' ? null : $request->string('status')->toString(), $requestId);
            $data = $request->operation() === 'detail' ? $this->inbox->read($actor, $message, $requestId) : $this->inbox->record($message, true);

            return new JsonResponse(['data' => $data], headers: ['Cache-Control' => 'private, no-store', 'ETag' => VersionPrecondition::etag($message->id, $message->lock_version)]);
        });
    }
}
