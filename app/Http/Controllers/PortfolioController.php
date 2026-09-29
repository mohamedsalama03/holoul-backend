<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PublicServices\PortfolioApi;
use App\Http\Requests\PortfolioRequest;
use Symfony\Component\HttpFoundation\Response;

final readonly class PortfolioController
{
    public function __construct(private PortfolioApi $api) {}

    public function __invoke(PortfolioRequest $request): Response
    {
        return $this->api->handle($request);
    }
}
