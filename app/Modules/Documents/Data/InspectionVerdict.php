<?php

declare(strict_types=1);

namespace App\Modules\Documents\Data;

use InvalidArgumentException;

final readonly class InspectionVerdict
{
    public function __construct(public bool $safe, public ?string $reasonCode = null)
    {
        if (($safe && $reasonCode !== null) || (! $safe && ! in_array($reasonCode, [
            'invalid_structure', 'dangerous_content', 'resource_limit', 'encrypted_document', 'format_mismatch',
        ], true))) {
            throw new InvalidArgumentException('Invalid inspection outcome.');
        }
    }
}
