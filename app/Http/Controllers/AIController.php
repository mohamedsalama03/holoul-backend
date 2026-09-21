<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\AI\AIApi;
use App\Http\Requests\AIHttpRequest;
use Illuminate\Http\JsonResponse;

final class AIController
{
    public function __invoke(AIHttpRequest $request, AIApi $api): JsonResponse
    {
        return $api->handle($request, $request->operation(), $request->validated());
    }
}
