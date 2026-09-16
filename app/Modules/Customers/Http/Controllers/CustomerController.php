<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Customers\Actions\ListCustomers;
use App\Modules\Customers\Actions\ReadCustomer;
use App\Modules\Customers\Actions\UpdateCustomer;
use App\Modules\Customers\Authorization\CustomerOwnership;
use App\Modules\Customers\Data\CustomerProfile;
use App\Modules\Customers\Http\CustomerInput;
use App\Modules\Identity\Contracts\CurrentIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class CustomerController
{
    public function __construct(private CurrentIdentity $identity, private CustomerOwnership $ownership, private ReadCustomer $readCustomer) {}

    public function index(Request $request, ListCustomers $customers): JsonResponse
    {
        $profiles = $customers->handle($this->identity->fromRequest($request)->id, CustomerInput::search($request));

        return new JsonResponse(['data' => array_map(fn (CustomerProfile $profile): array => $profile->toArray(), $profiles)], headers: ['Cache-Control' => 'no-store']);
    }

    public function show(Request $request, string $customer): JsonResponse
    {
        return $this->read($request, $customer);
    }

    public function nestedShow(Request $request, string $identity, string $customer): JsonResponse
    {
        return $this->read($request, $customer, $identity);
    }

    public function update(Request $request, string $customer, UpdateCustomer $update): JsonResponse
    {
        return $this->write($request, $customer, $update);
    }

    public function nestedUpdate(Request $request, string $identity, string $customer, UpdateCustomer $update): JsonResponse
    {
        return $this->write($request, $customer, $update, $identity);
    }

    private function read(Request $request, string $customer, ?string $identity = null): JsonResponse
    {
        $profile = $this->readCustomer->handle($this->identity->fromRequest($request)->id, $customer, $identity);

        return new JsonResponse(['data' => $profile->toArray()], headers: ['Cache-Control' => 'no-store']);
    }

    private function write(Request $request, string $customer, UpdateCustomer $update, ?string $identity = null): JsonResponse
    {
        $actor = $this->identity->fromRequest($request);
        $this->ownership->find($actor->id, $customer, $identity);
        $profile = $update->handle($actor->id, $customer, CustomerInput::phone($request), $request->attributes->getString('request_id'), $identity);

        return new JsonResponse(['data' => $profile->toArray()], headers: ['Cache-Control' => 'no-store']);
    }
}
