<?php

declare(strict_types=1);

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['identity.spa', 'identity.auth'])->group(function (): void {
    Route::post('project-requests/{projectRequest}/documents', DocumentController::class)->defaults('operation', 'reserve');
    Route::put('project-requests/{projectRequest}/documents/{document}/content', DocumentController::class)->defaults('operation', 'content');
    Route::get('project-requests/{projectRequest}/documents/{document}', DocumentController::class)->defaults('operation', 'metadata');
    Route::get('project-requests/{projectRequest}/documents/{document}/download', DocumentController::class)->defaults('operation', 'download');
    Route::delete('project-requests/{projectRequest}/documents/{document}', DocumentController::class)->defaults('operation', 'remove');
    Route::post('project-requests/{projectRequest}/documents/{document}/scan-retries', DocumentController::class)->defaults('operation', 'retry');
    Route::get('admin/project-requests/{projectRequest}/documents/{document}', DocumentController::class)->defaults('operation', 'metadata');
    Route::get('admin/project-requests/{projectRequest}/documents/{document}/download', DocumentController::class)->defaults('operation', 'download');
});
