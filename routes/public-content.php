<?php

declare(strict_types=1);

use App\Application\PublicServices\PublicContactSession;
use App\Application\PublicServices\PublicPortfolioCache;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PublicContentCapabilitiesController;
use App\Modules\Identity\Http\ExactOrigin;
use Illuminate\Support\Facades\Route;

Route::post('public/contact-messages', ContactController::class)->defaults('operation', 'create')
    ->middleware(['identity.spa', PublicContactSession::class]);
Route::middleware(['identity.spa', 'identity.auth'])->group(function (): void {
    Route::get('admin/public-content/capabilities', PublicContentCapabilitiesController::class);
    Route::get('admin/contact-messages', ContactController::class)->defaults('operation', 'list');
    Route::get('admin/contact-messages/{message}', ContactController::class)->defaults('operation', 'detail');
    Route::patch('admin/contact-messages/{message}', ContactController::class)->defaults('operation', 'update');
    Route::delete('admin/contact-messages/{message}', ContactController::class)->defaults('operation', 'redact');
});

Route::middleware([PublicPortfolioCache::class, ExactOrigin::class])->prefix('public/portfolio')->group(function (): void {
    Route::get('projects', PortfolioController::class)->defaults('operation', 'public_list');
    Route::get('projects/{project}', PortfolioController::class)->defaults('operation', 'public_detail');
    Route::get('categories', PortfolioController::class)->defaults('operation', 'public_categories');
    Route::get('images/{image}/{variant}', PortfolioController::class)->defaults('operation', 'public_image');
});
Route::middleware(['identity.spa', 'identity.auth'])->prefix('admin/portfolio/projects')->group(function (): void {
    Route::get('', PortfolioController::class)->defaults('operation', 'list');
    Route::post('', PortfolioController::class)->defaults('operation', 'create');
    Route::get('{project}', PortfolioController::class)->defaults('operation', 'detail');
    Route::patch('{project}', PortfolioController::class)->defaults('operation', 'update');
    Route::post('{project}/images', PortfolioController::class)->defaults('operation', 'reserve');
    Route::put('{project}/images/{image}/content', PortfolioController::class)->defaults('operation', 'upload');
    Route::get('{project}/images/{image}', PortfolioController::class)->defaults('operation', 'image_status');
    Route::delete('{project}/images/{image}', PortfolioController::class)->defaults('operation', 'remove');
    Route::post('{project}/publications', PortfolioController::class)->defaults('operation', 'publish');
    Route::post('{project}/unpublications', PortfolioController::class)->defaults('operation', 'unpublish');
});
