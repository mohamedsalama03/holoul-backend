<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mfa\Controllers;

use App\Modules\Identity\Mfa\MfaActions;
use App\Modules\Identity\Mfa\Requests\EmptyMfaRequest;
use App\Modules\Identity\Mfa\Requests\MfaCodeRequest;
use Illuminate\Http\JsonResponse;

final readonly class MfaController
{
    public function __construct(private MfaActions $actions) {}

    public function enroll(EmptyMfaRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->actions->beginEnrollment($request)], headers: ['Cache-Control' => 'no-store']);
    }

    public function confirm(MfaCodeRequest $request): JsonResponse
    {
        return response()->json(['data' => ['recovery_codes' => $this->actions->confirmEnrollment($request, $request->validatedCode())]], headers: ['Cache-Control' => 'no-store']);
    }

    public function challenge(MfaCodeRequest $request): JsonResponse
    {
        $this->actions->challenge($request, $request->validatedCode());

        return response()->json(['data' => ['authenticated' => true]], headers: ['Cache-Control' => 'no-store']);
    }

    public function recover(MfaCodeRequest $request): JsonResponse
    {
        $this->actions->recover($request, $request->validatedCode());

        return response()->json(['data' => ['authenticated' => true]], headers: ['Cache-Control' => 'no-store']);
    }

    public function regenerate(EmptyMfaRequest $request): JsonResponse
    {
        return response()->json(['data' => ['recovery_codes' => $this->actions->regenerateRecoveryCodes($request)]], headers: ['Cache-Control' => 'no-store']);
    }

    public function disable(EmptyMfaRequest $request): JsonResponse
    {
        $this->actions->disable($request);

        return response()->json(['data' => ['mfa_enabled' => false]], headers: ['Cache-Control' => 'no-store']);
    }
}
