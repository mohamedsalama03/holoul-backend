<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Notifications\NotificationApi;
use App\Http\Requests\NotificationHttpRequest;
use Illuminate\Http\JsonResponse;

final class NotificationController
{
    public function __invoke(NotificationHttpRequest $request, NotificationApi $api): JsonResponse
    {
        return $api->handle($request, $request->operation(), $request->validated());
    }
}
