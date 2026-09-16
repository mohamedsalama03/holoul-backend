<?php

declare(strict_types=1);

namespace App\Modules\Customers\Authorization;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Contracts\IdentityReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class CustomerOwnership
{
    public function __construct(private IdentityReader $identities) {}

    /** @return Builder<Customer> */
    public function scope(string $actorId): Builder
    {
        self::requireUuid($actorId);
        $identity = $this->identities->find($actorId);

        if ($identity === null || ! $identity->enabled || $identity->kind !== 'customer') {
            throw new NotFoundHttpException;
        }

        // Every list/search/lookup begins with this ownership predicate.
        return Customer::query()->where('user_id', $identity->id);
    }

    public function find(string $actorId, string $customerId, ?string $parentIdentityId = null): Customer
    {
        self::requireUuid($customerId);
        $query = $this->scope($actorId)->whereKey($customerId);

        if ($parentIdentityId !== null) {
            self::requireUuid($parentIdentityId);
            $query->where('user_id', $parentIdentityId);
        }

        return $query->first() ?? throw new NotFoundHttpException;
    }

    public static function requireUuid(string $id): void
    {
        if (! Str::isUuid($id)) {
            throw new NotFoundHttpException;
        }
    }
}
