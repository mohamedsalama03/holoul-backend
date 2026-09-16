<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Health\Readiness;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    public function live(): JsonResponse
    {
        return response()->json(['data' => ['status' => 'alive']], headers: ['Cache-Control' => 'no-store']);
    }

    public function ready(Readiness $readiness): JsonResponse
    {
        return $readiness->ready()
            ? response()->json(['data' => ['status' => 'ready']], headers: ['Cache-Control' => 'no-store'])
            : response()->json(['error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'The service is temporarily unavailable.'], 'request_id' => request()->attributes->get('request_id')], 503, ['Cache-Control' => 'no-store']);
    }
}
