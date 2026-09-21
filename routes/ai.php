<?php

declare(strict_types=1);

use App\Http\Controllers\AIController;
use Illuminate\Support\Facades\Route;

Route::middleware(['identity.spa', 'identity.auth'])->group(function (): void {
    Route::get('ai-runs', AIController::class)->defaults('operation', 'list');
    Route::post('ai-runs', AIController::class)->defaults('operation', 'create');
    Route::get('ai-runs/{aiRun}', AIController::class)->defaults('operation', 'detail');
    Route::post('ai-runs/{aiRun}/applications', AIController::class)->defaults('operation', 'apply');
    Route::post('ai-runs/{aiRun}/dismissals', AIController::class)->defaults('operation', 'dismiss');
    Route::post('ai-runs/{aiRun}/cancellations', AIController::class)->defaults('operation', 'cancel');
});
