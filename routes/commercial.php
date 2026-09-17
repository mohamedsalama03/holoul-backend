<?php

declare(strict_types=1);

use App\Http\Controllers\CommercialController;
use App\Http\Controllers\ProposalDocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['identity.spa', 'identity.auth'])->group(function (): void {
    foreach (['admin/project-requests/{projectRequest}', 'project-requests/{projectRequest}'] as $prefix) {
        Route::get($prefix.'/proposals/{proposal}/documents/{document}', ProposalDocumentController::class);
        Route::get($prefix.'/proposals/{proposal}/documents/{document}/download', ProposalDocumentController::class);
    }
    Route::prefix('admin/project-requests/{projectRequest}')->group(function (): void {
        Route::get('discovery', CommercialController::class)->defaults('operation', 'discovery.list');
        Route::post('discovery', CommercialController::class)->defaults('operation', 'discovery.create');
        Route::get('discovery/{revision}', CommercialController::class)->defaults('operation', 'discovery.detail');
        Route::put('discovery/{revision}', CommercialController::class)->defaults('operation', 'discovery.update');
        Route::put('discovery/{revision}/requirements', CommercialController::class)->defaults('operation', 'discovery.requirements');
        Route::post('discovery/{revision}/starts', CommercialController::class)->defaults('operation', 'discovery.start');
        Route::post('discovery/{revision}/completions', CommercialController::class)->defaults('operation', 'discovery.complete');
        Route::get('proposals', CommercialController::class)->defaults('operation', 'proposal.list');
        Route::post('proposals', CommercialController::class)->defaults('operation', 'proposal.create');
        Route::get('proposals/{proposal}', CommercialController::class)->defaults('operation', 'proposal.detail');
        Route::put('proposals/{proposal}', CommercialController::class)->defaults('operation', 'proposal.update');
        Route::post('proposals/{proposal}/approvals', CommercialController::class)->defaults('operation', 'proposal.approve');
        Route::post('proposals/{proposal}/issuances', CommercialController::class)->defaults('operation', 'proposal.issue');
        Route::post('proposals/{proposal}/supersessions', CommercialController::class)->defaults('operation', 'proposal.supersede');
        Route::post('proposals/{proposal}/withdrawals', CommercialController::class)->defaults('operation', 'proposal.withdraw');
        Route::post('proposals/{proposal}/documents', CommercialController::class)->defaults('operation', 'proposal.attach_document');
        Route::delete('proposals/{proposal}/documents/{document}', CommercialController::class)->defaults('operation', 'proposal.remove_document');
    });
    Route::prefix('project-requests/{projectRequest}')->group(function (): void {
        Route::get('proposals', CommercialController::class)->defaults('operation', 'proposal.list');
        Route::get('proposals/{proposal}', CommercialController::class)->defaults('operation', 'proposal.detail');
        Route::post('proposals/{proposal}/acceptances', CommercialController::class)->defaults('operation', 'proposal.accept');
        Route::post('proposals/{proposal}/declines', CommercialController::class)->defaults('operation', 'proposal.decline');
        Route::post('proposals/{proposal}/rescissions', CommercialController::class)->defaults('operation', 'proposal.rescind');
    });
});
