<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Intake\GuestIntakeApi;
use App\Http\Requests\GuestIntakeRequest;
use Illuminate\Http\JsonResponse;

final class GuestIntakeController
{
    public function __invoke(GuestIntakeRequest $request, GuestIntakeApi $api): JsonResponse
    {
        return $api->handle($request, $request->operation(), $request->validated());
    }
}
