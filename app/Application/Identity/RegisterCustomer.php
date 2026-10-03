<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Customers\Actions\CreateCustomer;
use App\Modules\Customers\Data\InternationalPhone;
use App\Modules\Identity\Actions\CreateCustomerIdentity;
use App\Modules\Identity\Authorization\Actions\AssignCustomerRole;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/** Application orchestration composes module-owned actions in one transaction. */
final readonly class RegisterCustomer
{
    public function __construct(private CreateCustomerIdentity $identities, private CreateCustomer $customers, private AssignCustomerRole $roles, private RecordAuditEvent $audit) {}

    public function handle(string $name, string $email, string $displayEmail, #[SensitiveParameter] string $password, string $phone, string $requestId): void
    {
        InternationalPhone::parse($phone, $phone);
        DB::transaction(function () use ($name, $email, $displayEmail, $password, $phone, $requestId): void {
            $user = $this->identities->handle($name, $email, $displayEmail, $password);
            if ($user === null) {
                return;
            }
            $this->customers->handle($user->id, $phone, $phone);
            $this->roles->handle($user->id, $requestId);
            $this->audit->handle('identity.registered', 'user', $user->id, $requestId, $user->id);
        });
    }
}
