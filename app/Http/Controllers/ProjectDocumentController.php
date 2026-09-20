<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Projects\ProjectDocumentApi;
use App\Http\Requests\ProjectDocumentHttpRequest;
use Symfony\Component\HttpFoundation\Response;

final class ProjectDocumentController
{
    public function __invoke(ProjectDocumentHttpRequest $request, ProjectDocumentApi $api): Response
    {
        return $api->handle($request, $request->operation(), $request->validated());
    }
}
