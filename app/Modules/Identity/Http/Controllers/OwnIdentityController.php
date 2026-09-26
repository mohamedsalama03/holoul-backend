<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\OwnIdentity;
use App\Modules\Identity\Http\Requests\EmptyRequest;
use App\Modules\Identity\Http\Requests\NameRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OwnIdentityController
{
    public function show(Request $request, OwnIdentity $action): JsonResponse
    {
        return response()->json(['data' => $action->read($request)], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function update(NameRequest $request, OwnIdentity $action): JsonResponse
    {
        $action->update($request, $request->string('full_name')->toString());

        return response()->json(['data' => $action->read($request)], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function sessions(Request $request, OwnIdentity $action): JsonResponse
    {
        return response()->json(['data' => $action->sessions($request)]);
    }

    public function revokeOthers(EmptyRequest $request, OwnIdentity $action): JsonResponse
    {
        $action->revokeOthers($request);

        return response()->json(['data' => ['message' => 'Other sessions revoked.']]);
    }
}
