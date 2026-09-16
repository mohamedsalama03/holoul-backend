<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization\Http\Controllers;

use App\Modules\Identity\Authorization\Actions\ChangeStaffAuthorization;
use App\Modules\Identity\Authorization\Actions\ReadStaffIdentity;
use App\Modules\Identity\Authorization\Http\StaffAuthorizationInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StaffAuthorizationController
{
    public function show(Request $request, string $user, ReadStaffIdentity $read): JsonResponse
    {
        return new JsonResponse(['data' => $read->handle($request, $user)->toArray()], headers: ['Cache-Control' => 'no-store']);
    }

    public function update(Request $request, string $user, ReadStaffIdentity $read, ChangeStaffAuthorization $change): JsonResponse
    {
        $read->handle($request, $user);
        $roles = StaffAuthorizationInput::roles($request);

        return new JsonResponse(['data' => $change->handle($request, $user, $roles, $request->boolean('enabled'))->toArray()], headers: ['Cache-Control' => 'no-store']);
    }
}
