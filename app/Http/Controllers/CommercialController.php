<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Commercial\CommercialApi;
use App\Http\Requests\CommercialHttpRequest;
use Illuminate\Http\JsonResponse;

final class CommercialController
{
    public function __invoke(CommercialHttpRequest $request, CommercialApi $api): JsonResponse
    {
        return $api->handle($request, $request->operation(), $request->validated());
    }
}
