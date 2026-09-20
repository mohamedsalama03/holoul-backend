<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Projects\ProjectApi;
use App\Http\Requests\ProjectHttpRequest;
use Illuminate\Http\JsonResponse;

final class ProjectController
{
    public function __invoke(ProjectHttpRequest $request, ProjectApi $api): JsonResponse
    {
        return $api->handle($request, $request->operation(), $request->validated());
    }
}
