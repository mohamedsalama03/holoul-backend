<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\EmailRequest;
use App\Modules\Identity\Http\Requests\ResetRequest;
use App\Modules\Identity\Http\Requests\TokenRequest;
use App\Modules\Identity\Recovery\RecoveryActions;
use App\Modules\Identity\Security\IdentityInput;
use Illuminate\Http\JsonResponse;

final class RecoveryController
{
    public function resend(EmailRequest $request, RecoveryActions $action): JsonResponse
    {
        $action->resendVerification($request->string('email')->toString(), IdentityInput::requestId($request));

        return $this->requested();
    }

    public function forgot(EmailRequest $request, RecoveryActions $action): JsonResponse
    {
        $action->forgotPassword($request->string('email')->toString(), IdentityInput::requestId($request));

        return $this->requested();
    }

    public function verify(TokenRequest $request, RecoveryActions $action): JsonResponse
    {
        $action->verifyEmail($request->string('token')->toString(), IdentityInput::requestId($request));

        return response()->json(['data' => ['message' => 'Email verified.']]);
    }

    public function reset(ResetRequest $request, RecoveryActions $action): JsonResponse
    {
        $action->resetPassword($request->string('token')->toString(), $request->string('password')->toString(), IdentityInput::requestId($request));

        return response()->json(['data' => ['message' => 'Password reset. Sign in again.']]);
    }

    private function requested(): JsonResponse
    {
        return response()->json(['data' => ['message' => 'If the account is eligible, instructions will be sent.']], 202);
    }
}
