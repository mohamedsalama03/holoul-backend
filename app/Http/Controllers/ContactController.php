<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PublicServices\ContactApi;
use App\Http\Requests\ContactRequest;
use Illuminate\Http\JsonResponse;

final class ContactController
{
    public function __invoke(ContactRequest $request, ContactApi $api): JsonResponse
    {
        return $api->handle($request);
    }
}
