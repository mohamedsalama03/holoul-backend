<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\CustomerController;
use App\Modules\Identity\Authorization\Http\Controllers\StaffAuthorizationController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\OwnIdentityController;
use App\Modules\Identity\Http\Controllers\RecoveryController;
use App\Modules\Identity\Mfa\Controllers\MfaController;
use App\Modules\Identity\Staff\StaffController;
use Illuminate\Support\Facades\Route;

Route::middleware('identity.spa')->group(function (): void {
    Route::post('auth/staff-invitations/lookup', [StaffController::class, 'lookup']);
    Route::post('auth/staff-invitations/accept', [StaffController::class, 'accept']);
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('identity.throttle:register');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('identity.throttle:login');
    Route::post('auth/username-login', [AuthController::class, 'usernameLogin'])->middleware('identity.throttle:login');
    Route::post('auth/email/verify', [RecoveryController::class, 'verify'])->middleware('identity.throttle:verify');
    Route::post('auth/email/resend', [RecoveryController::class, 'resend'])->middleware('identity.throttle:resend');
    Route::post('auth/password/forgot', [RecoveryController::class, 'forgot'])->middleware('identity.throttle:forgot');
    Route::post('auth/password/reset', [RecoveryController::class, 'reset'])->middleware('identity.throttle:reset');
    Route::post('auth/mfa/enrollment', [MfaController::class, 'enroll'])->middleware('identity.throttle:mfa');
    Route::post('auth/mfa/enrollment/confirm', [MfaController::class, 'confirm'])->middleware('identity.throttle:mfa');
    Route::post('auth/mfa/challenge', [MfaController::class, 'challenge'])->middleware('identity.throttle:mfa');
    Route::post('auth/mfa/recovery', [MfaController::class, 'recover'])->middleware('identity.throttle:mfa');

    Route::middleware('identity.auth')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/password/confirm', [AuthController::class, 'confirmPassword'])->middleware('identity.throttle:password');
        Route::post('auth/password/change', [AuthController::class, 'changePassword'])->middleware('identity.throttle:password');
        Route::get('identity/me', [OwnIdentityController::class, 'show']);
        Route::patch('identity/me', [OwnIdentityController::class, 'update']);
        Route::get('identity/sessions', [OwnIdentityController::class, 'sessions']);
        Route::post('identity/sessions/revoke-others', [OwnIdentityController::class, 'revokeOthers'])->middleware('identity.throttle:security');
        Route::post('auth/mfa/recovery-codes', [MfaController::class, 'regenerate'])->middleware('identity.throttle:mfa');
        Route::delete('auth/mfa', [MfaController::class, 'disable'])->middleware('identity.throttle:mfa');
        Route::get('customers', [CustomerController::class, 'index']);
        Route::get('customers/{customer}', [CustomerController::class, 'show']);
        Route::patch('customers/{customer}', [CustomerController::class, 'update']);
        Route::get('identities/{identity}/customers/{customer}', [CustomerController::class, 'nestedShow']);
        Route::patch('identities/{identity}/customers/{customer}', [CustomerController::class, 'nestedUpdate']);
        Route::get('identity/capabilities', [StaffController::class, 'capabilities']);
        Route::get('identity/staff', [StaffController::class, 'directory']);
        Route::post('identity/staff', [StaffController::class, 'create']);
        Route::get('identity/staff/invitations', [StaffController::class, 'invitations']);
        Route::post('identity/staff/invitations', [StaffController::class, 'issue']);
        Route::post('identity/staff/invitations/{invitation}/resends', [StaffController::class, 'resend']);
        Route::post('identity/staff/invitations/{invitation}/revocations', [StaffController::class, 'revoke']);
        Route::get('identity/staff/{user}/authorization', [StaffController::class, 'authorization']);
        Route::put('identity/staff/{user}/authorization', [StaffController::class, 'replaceAuthorization'])->middleware('identity.throttle:security');
        Route::get('identity/staff/{user}', [StaffAuthorizationController::class, 'show']);
        Route::patch('identity/staff/{user}/authorization', [StaffAuthorizationController::class, 'update'])->middleware('identity.throttle:security');
    });
});
