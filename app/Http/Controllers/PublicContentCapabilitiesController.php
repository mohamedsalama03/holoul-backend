<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PublicServices\PublicContentCapabilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicContentCapabilitiesController
{
    public function __invoke(Request $request, PublicContentCapabilities $capabilities): JsonResponse
    {
        return $capabilities->read($request);
    }
}
