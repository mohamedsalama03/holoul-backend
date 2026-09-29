<?php

declare(strict_types=1);

namespace App\Modules\Contact\Data;

final readonly class ContactSubmission
{
    public function __construct(public string $fullName, public string $email, public string $phone, public ?string $company, public string $message) {}

    /** @return array{full_name:string,email:string,phone:string,company:?string,message:string} */
    public function fields(): array
    {
        return ['full_name' => $this->fullName, 'email' => $this->email, 'phone' => $this->phone, 'company' => $this->company, 'message' => $this->message];
    }
}
