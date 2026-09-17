<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Commercial\ProposalDocumentApi;
use App\Http\Requests\CommercialHttpRequest;
use Symfony\Component\HttpFoundation\Response;

final class ProposalDocumentController
{
    public function __invoke(CommercialHttpRequest $request, ProposalDocumentApi $api): Response
    {
        return $api->handle($request);
    }
}
