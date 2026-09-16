<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

final class EmptyRequest extends IdentityRequest
{
    protected function allowedFields(): array
    {
        return [];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
