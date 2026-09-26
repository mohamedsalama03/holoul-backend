<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Administration\AdminIntegrationApi;
use App\Http\Requests\AdminIntegrationRequest;
use Illuminate\Http\JsonResponse;

final class AdminIntegrationController
{
    public function __invoke(AdminIntegrationRequest $request, AdminIntegrationApi $api): JsonResponse
    {
        return $api->handle($request, $request->operation(), $request->validated());
    }
}
