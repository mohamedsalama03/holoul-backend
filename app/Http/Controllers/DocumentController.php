<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Documents\DocumentApi;
use App\Http\Requests\DocumentHttpRequest;
use Symfony\Component\HttpFoundation\Response;

final class DocumentController
{
    public function __invoke(DocumentHttpRequest $request, DocumentApi $api): Response
    {
        return $api->handle($request, $request->operation(), $request->validated());
    }
}
