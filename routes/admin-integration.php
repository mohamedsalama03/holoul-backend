<?php

declare(strict_types=1);

use App\Http\Controllers\AdminIntegrationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['identity.spa', 'identity.auth'])->prefix('admin')->group(function (): void {
    Route::get('customers', AdminIntegrationController::class)->defaults('operation', 'customers.list');
    Route::get('customers/{customer}', AdminIntegrationController::class)->defaults('operation', 'customers.detail');
    Route::get('customers/{customer}/project-requests', AdminIntegrationController::class)->defaults('operation', 'customers.requests');
    Route::get('customers/{customer}/projects', AdminIntegrationController::class)->defaults('operation', 'customers.projects');
    Route::get('project-requests/{projectRequest}/eligible-assignees', AdminIntegrationController::class)->defaults('operation', 'intake.eligible');
    Route::get('projects/{project}/eligible-staff', AdminIntegrationController::class)->defaults('operation', 'projects.eligible');
});
