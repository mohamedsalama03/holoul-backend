<?php

declare(strict_types=1);

use App\Application\Intake\GuestIntakeThrottle;
use App\Http\Controllers\GuestIntakeController;
use App\Modules\Identity\Http\ExactOrigin;
use Illuminate\Support\Facades\Route;

Route::middleware([ExactOrigin::class, GuestIntakeThrottle::class])->group(function (): void {
    Route::get('intake/categories', GuestIntakeController::class)->defaults('operation', 'categories');
    Route::get('intake/categories/{category}/subcategories', GuestIntakeController::class)->defaults('operation', 'subcategories');
});
Route::middleware(['identity.spa', GuestIntakeThrottle::class])->group(function (): void {
    Route::post('guest/project-requests', GuestIntakeController::class)->defaults('operation', 'create');
    Route::post('guest/project-requests/{projectRequest}/submissions', GuestIntakeController::class)->defaults('operation', 'submit');
    Route::post('guest/project-requests/{projectRequest}/documents', GuestIntakeController::class)->defaults('operation', 'reserve');
    Route::put('guest/project-requests/{projectRequest}/documents/{document}/content', GuestIntakeController::class)->defaults('operation', 'content');
    Route::get('guest/project-requests/{projectRequest}/documents/{document}', GuestIntakeController::class)->defaults('operation', 'metadata');
});
Route::post('project-request-claims', GuestIntakeController::class)->defaults('operation', 'claim')
    ->middleware(['identity.spa', 'identity.auth', GuestIntakeThrottle::class]);
