<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;

/** B0 requires a CSRF token even when Sec-Fetch-Site says same-origin, including tests. */
final class StrictCsrf extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }

    /** @param Request $request */
    protected function hasValidOrigin($request): bool
    {
        return false;
    }
}
