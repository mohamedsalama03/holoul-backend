<?php

declare(strict_types=1);

namespace App\Modules\Contact;

final class ContactMailNotAccepted extends \RuntimeException
{
    public function __construct(public readonly bool $retryable = true)
    {
        parent::__construct('Contact mail not accepted.');
    }
}
