<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Reporting\ReportingApi;
use App\Http\Requests\ReportingHttpRequest;
use Illuminate\Http\JsonResponse;

final class ReportingController
{
    public function __invoke(ReportingHttpRequest $request, ReportingApi $api): JsonResponse
    {
        return $api->handle($request, $request->report(), $request->validated());
    }
}
