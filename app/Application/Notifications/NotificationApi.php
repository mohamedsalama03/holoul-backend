<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Modules\Identity\Actions\WithAuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Security\AuthLimiter;
use App\Modules\Identity\Security\SessionSecurity;
use App\Modules\Notifications\Actions\NotificationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class NotificationApi
{
    public function __construct(private WithAuthorizedIdentity $identity, private SessionSecurity $sessions, private AuthLimiter $limits, private NotificationAccess $access) {}

    /** @param array<string,mixed> $input */
    public function handle(Request $request, string $operation, array $input): JsonResponse
    {
        return $this->identity->handle($request, function (AuthorizedIdentity $actor) use ($request, $operation, $input): JsonResponse {
            $this->limits->consume([['key' => 'notifications:'.$actor->id, 'maximum' => 120, 'seconds' => 60]]);
            if ($operation === 'replay') {
                $this->sessions->requireRecentPassword($request);
            }
            $id = $request->route('notification') ?? $request->route('delivery');
            $correlation = $request->attributes->get('request_id');
            $result = $this->access->handle($actor, $operation, is_string($id) ? $id : '', $input, $request->header('If-Match'),
                $request->header('Idempotency-Key'), is_string($correlation) ? $correlation : (string) Str::uuid7());
            $headers = ['Cache-Control' => 'private, no-store'];
            if (isset($result['etag'])) {
                if (! is_string($result['etag'])) {
                    throw new \LogicException('Invalid notification ETag.');
                }
                $headers['ETag'] = $result['etag'];
                unset($result['etag']);
            }

            return new JsonResponse($result, 200, $headers);
        });
    }
}
