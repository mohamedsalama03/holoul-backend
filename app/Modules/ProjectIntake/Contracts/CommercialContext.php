<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Contracts;

use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Fresh facts from an authorized, locked request; never populated by HTTP input. */
final readonly class CommercialContext
{
    /** @param list<string> $permissions */
    public function __construct(public string $requestId, public string $customerId, public string $customerUserId,
        public string $state, public int $version, public string $intakeRevisionId, public string $actorId,
        public bool $customer, public bool $verified, public bool $recentAuthentication,
        public ?string $assignedStaffId, public array $permissions, public string $correlationId) {}

    public function staff(string $permission): void
    {
        if ($this->customer || ! in_array($permission, $this->permissions, true)
            || ($this->assignedStaffId !== $this->actorId && ! in_array('intake.read_all', $this->permissions, true))) {
            throw new AuthorizationException;
        }
    }

    public function owner(string $permission, bool $decision = false): void
    {
        if (! $this->customer || $this->actorId !== $this->customerUserId || ! in_array($permission, $this->permissions, true)
            || ($decision && (! $this->verified || ! $this->recentAuthentication))) {
            throw new AuthorizationException;
        }
    }

    /** @param list<string> $states */
    public function requireState(array $states): void
    {
        if (! in_array($this->state, $states, true)) {
            throw new HttpException(409);
        }
    }
}
