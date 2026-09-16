<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Application\Identity\RegisterCustomer;
use App\Modules\Identity\Actions\Authentication;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Http\Requests\EmptyRequest;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\PasswordRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Security\IdentityInput;
use Illuminate\Http\JsonResponse;

final class AuthController
{
    public function register(RegisterRequest $request, RegisterCustomer $action): JsonResponse
    {
        $display = $request->attributes->get('email_display');
        $action->handle($request->string('full_name')->toString(), $request->string('email')->toString(),
            is_string($display) ? $display : $request->string('email')->toString(),
            $request->string('password')->toString(), $request->string('phone')->toString(), IdentityInput::requestId($request));

        return response()->json(['data' => ['message' => 'If registration is available, verification instructions will be sent.']], 202);
    }

    public function login(LoginRequest $request, Authentication $action): JsonResponse
    {
        $result = $action->login($request, $request->string('email')->toString(), $request->string('password')->toString());

        return response()->json(['data' => $result], $result['next_step'] === 'authenticated' ? 200 : 202);
    }

    public function logout(EmptyRequest $request, Authentication $action): JsonResponse
    {
        $action->logout($request);

        return response()->json(['data' => ['message' => 'Signed out.']]);
    }

    public function confirmPassword(PasswordRequest $request, Authentication $action): JsonResponse
    {
        $action->confirmPassword($request, $request->string('password')->toString());

        return response()->json(['data' => ['message' => 'Password confirmed.']]);
    }

    public function changePassword(ChangePasswordRequest $request, Authentication $action): JsonResponse
    {
        $action->changePassword($request, $request->string('current_password')->toString(), $request->string('password')->toString());

        return response()->json(['data' => ['message' => 'Password changed and other sessions revoked.']]);
    }
}
