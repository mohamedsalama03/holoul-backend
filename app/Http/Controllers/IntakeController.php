<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Intake\IntakeApi;
use App\Http\Requests\IntakeHttpRequest;
use Illuminate\Http\JsonResponse;

final class IntakeController
{
    public function __invoke(IntakeHttpRequest $request, IntakeApi $api): JsonResponse
    {
        return $api->handle($request, $request->operation(), $request->validated());
    }
}
