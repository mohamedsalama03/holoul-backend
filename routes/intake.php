<?php

declare(strict_types=1);

use App\Http\Controllers\IntakeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['identity.spa', 'identity.auth'])->group(function (): void {
    Route::get('categories', IntakeController::class)->defaults('operation', 'taxonomy.categories');
    Route::get('categories/{category}/subcategories', IntakeController::class)->defaults('operation', 'taxonomy.subcategories');
    Route::get('admin/categories', IntakeController::class)->defaults('operation', 'taxonomy.admin_categories');
    Route::get('admin/categories/{category}', IntakeController::class)->defaults('operation', 'taxonomy.category');
    Route::get('admin/subcategories/{subcategory}', IntakeController::class)->defaults('operation', 'taxonomy.subcategory');
    Route::get('admin/categories/{category}/subcategories', IntakeController::class)->defaults('operation', 'taxonomy.admin_subcategories');
    Route::post('admin/categories', IntakeController::class)->defaults('operation', 'taxonomy.create_category');
    Route::post('admin/categories/{category}/subcategories', IntakeController::class)->defaults('operation', 'taxonomy.create_subcategory');
    Route::patch('admin/categories/{category}', IntakeController::class)->defaults('operation', 'taxonomy.update_category');
    Route::patch('admin/subcategories/{subcategory}', IntakeController::class)->defaults('operation', 'taxonomy.update_subcategory');
    Route::get('project-requests', IntakeController::class)->defaults('operation', 'customer.list');
    Route::post('project-requests', IntakeController::class)->defaults('operation', 'customer.create');
    Route::get('project-requests/by-reference/{reference}', IntakeController::class)->defaults('operation', 'customer.reference');
    Route::get('project-requests/{projectRequest}', IntakeController::class)->defaults('operation', 'customer.detail');
    Route::get('customers/{customer}/project-requests/{projectRequest}', IntakeController::class)->defaults('operation', 'customer.nested');
    Route::patch('project-requests/{projectRequest}/draft', IntakeController::class)->defaults('operation', 'customer.update');
    Route::post('project-requests/{projectRequest}/amendments', IntakeController::class)->defaults('operation', 'customer.amend');
    Route::post('project-requests/{projectRequest}/submissions', IntakeController::class)->defaults('operation', 'customer.submit');
    Route::get('project-requests/{projectRequest}/revisions', IntakeController::class)->defaults('operation', 'customer.revisions');
    Route::get('project-requests/{projectRequest}/revisions/{revision}', IntakeController::class)->defaults('operation', 'customer.revision');
    Route::get('project-requests/{projectRequest}/information-requests', IntakeController::class)->defaults('operation', 'customer.information');
    Route::post('project-requests/{projectRequest}/information-requests/{information}/responses', IntakeController::class)->defaults('operation', 'customer.response');
    Route::post('project-requests/{projectRequest}/withdrawals', IntakeController::class)->defaults('operation', 'customer.withdraw');
    Route::get('project-requests/{projectRequest}/history', IntakeController::class)->defaults('operation', 'customer.history');
    Route::get('admin/project-requests', IntakeController::class)->defaults('operation', 'staff.list');
    Route::get('admin/project-requests/by-reference/{reference}', IntakeController::class)->defaults('operation', 'staff.reference');
    Route::get('admin/project-requests/{projectRequest}', IntakeController::class)->defaults('operation', 'staff.detail');
    Route::get('admin/project-requests/{projectRequest}/revisions', IntakeController::class)->defaults('operation', 'staff.revisions');
    Route::get('admin/project-requests/{projectRequest}/revisions/{revision}', IntakeController::class)->defaults('operation', 'staff.revision');
    Route::get('admin/project-requests/{projectRequest}/information-requests', IntakeController::class)->defaults('operation', 'staff.information');
    Route::get('admin/project-requests/{projectRequest}/history', IntakeController::class)->defaults('operation', 'staff.history');
    Route::post('admin/project-requests/{projectRequest}/assignments', IntakeController::class)->defaults('operation', 'staff.assign');
    Route::get('admin/project-requests/{projectRequest}/assignments', IntakeController::class)->defaults('operation', 'staff.assignments');
    Route::post('admin/project-requests/{projectRequest}/reviews', IntakeController::class)->defaults('operation', 'staff.review');
    Route::post('admin/project-requests/{projectRequest}/information-requests', IntakeController::class)->defaults('operation', 'staff.ask');
    Route::post('admin/project-requests/{projectRequest}/information-requests/{information}/acknowledgements', IntakeController::class)->defaults('operation', 'staff.acknowledge');
    Route::post('admin/project-requests/{projectRequest}/discovery-handoffs', IntakeController::class)->defaults('operation', 'staff.discovery');
    Route::post('admin/project-requests/{projectRequest}/rejections', IntakeController::class)->defaults('operation', 'staff.reject');
});
