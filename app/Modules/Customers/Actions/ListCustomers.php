<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Authorization\CustomerOwnership;
use App\Modules\Customers\Data\CustomerProfile;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final readonly class ListCustomers
{
    public function __construct(private CustomerOwnership $ownership) {}

    /** @return list<CustomerProfile> */
    public function handle(string $actorId, string $search = ''): array
    {
        $query = $this->ownership->scope($actorId);

        if (strlen($search) > 64 || preg_match('/[\x00-\x1f\x7f]/', $search) === 1) {
            throw ValidationException::withMessages(['search' => 'The search value is invalid.']);
        }

        if ($search !== '') {
            $literal = '%'.addcslashes($search, '\\%_').'%';
            $query->where(function (Builder $query) use ($literal): void {
                $query->where('phone_e164', 'like', $literal)->orWhere('phone_display', 'like', $literal);
            });
        }

        return array_values($query->orderBy('id')->limit(25)->get()->map(fn (Customer $customer): CustomerProfile => CustomerProfile::fromModel($customer))->all());
    }
}
