<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use InvalidArgumentException;
use RuntimeException;

final class DocumentExtractionRejected extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        if (! in_array($reasonCode, ['no_text', 'resource_limit', 'invalid_structure', 'dangerous_content', 'encrypted_document', 'format_mismatch'], true)) {
            throw new InvalidArgumentException('Invalid extraction failure code.');
        }
        parent::__construct('The approved document could not be extracted safely.');
    }
}
