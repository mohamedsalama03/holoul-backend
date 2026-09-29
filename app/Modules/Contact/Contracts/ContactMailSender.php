<?php

declare(strict_types=1);

namespace App\Modules\Contact\Contracts;

use App\Modules\Contact\Data\ContactMail;

interface ContactMailSender
{
    public function send(ContactMail $mail): void;
}
