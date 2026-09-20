<?php

declare(strict_types=1);

use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['identity.spa', 'identity.auth'])->group(function (): void {
    Route::post('admin/project-requests/{projectRequest}/conversions', ProjectController::class)->defaults('operation', 'project.convert');
    foreach (['admin/projects', 'projects'] as $prefix) {
        Route::get($prefix, ProjectController::class)->defaults('operation', 'project.list');
        Route::get($prefix.'/{project}', ProjectController::class)->defaults('operation', 'project.detail');
        Route::get($prefix.'/{project}/milestones', ProjectController::class)->defaults('operation', 'project.milestones');
        Route::get($prefix.'/{project}/updates', ProjectController::class)->defaults('operation', 'project.updates');
        Route::get($prefix.'/{project}/documents', ProjectDocumentController::class)->defaults('operation', 'list');
        Route::get($prefix.'/{project}/documents/{document}', ProjectDocumentController::class)->defaults('operation', 'metadata');
        Route::get($prefix.'/{project}/documents/{document}/download', ProjectDocumentController::class)->defaults('operation', 'download');
    }
    Route::post('projects/{project}/completion-confirmations', ProjectController::class)->defaults('operation', 'project.confirm');
    Route::prefix('admin/projects/{project}')->group(function (): void {
        Route::get('activity', ProjectController::class)->defaults('operation', 'project.activity');
        Route::get('team-members', ProjectController::class)->defaults('operation', 'project.team');
        Route::post('team-members', ProjectController::class)->defaults('operation', 'project.team.add');
        Route::delete('team-members/{member}', ProjectController::class)->defaults('operation', 'project.team.remove');
        Route::get('evidence', ProjectController::class)->defaults('operation', 'project.evidence.list');
        Route::post('evidence', ProjectController::class)->defaults('operation', 'project.evidence');
        Route::post('advances', ProjectController::class)->defaults('operation', 'project.advance');
        Route::post('holds', ProjectController::class)->defaults('operation', 'project.hold');
        Route::post('resumptions', ProjectController::class)->defaults('operation', 'project.resume');
        Route::post('failures', ProjectController::class)->defaults('operation', 'project.fail');
        Route::post('cancellations', ProjectController::class)->defaults('operation', 'project.cancel');
        Route::post('milestones', ProjectController::class)->defaults('operation', 'project.milestone.create');
        Route::patch('milestones/{milestone}', ProjectController::class)->defaults('operation', 'project.milestone.update');
        Route::post('milestones/{milestone}/starts', ProjectController::class)->defaults('operation', 'project.milestone.start');
        Route::post('milestones/{milestone}/delays', ProjectController::class)->defaults('operation', 'project.milestone.delay');
        Route::post('milestones/{milestone}/completions', ProjectController::class)->defaults('operation', 'project.milestone.complete');
        Route::post('updates', ProjectController::class)->defaults('operation', 'project.update.publish');
        Route::post('documents', ProjectDocumentController::class)->defaults('operation', 'reserve');
        Route::delete('documents/{document}', ProjectDocumentController::class)->defaults('operation', 'remove');
        Route::put('documents/{document}/content', ProjectDocumentController::class)->defaults('operation', 'content');
        Route::post('documents/{document}/scan-retries', ProjectDocumentController::class)->defaults('operation', 'retry');
    });
});
