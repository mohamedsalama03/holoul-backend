<?php

declare(strict_types=1);

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['identity.spa', 'identity.auth'])->group(function (): void {
    Route::get('notifications', NotificationController::class)->defaults('operation', 'list');
    Route::get('notifications/unread-count', NotificationController::class)->defaults('operation', 'unread');
    Route::post('notifications/read-all', NotificationController::class)->defaults('operation', 'read_all');
    Route::get('notifications/preferences', NotificationController::class)->defaults('operation', 'preferences');
    Route::patch('notifications/preferences', NotificationController::class)->defaults('operation', 'preferences_update');
    Route::get('notifications/{notification}', NotificationController::class)->defaults('operation', 'detail');
    Route::post('notifications/{notification}/read', NotificationController::class)->defaults('operation', 'read');
    Route::get('admin/notification-deliveries', NotificationController::class)->defaults('operation', 'deliveries');
    Route::get('admin/notification-deliveries/{delivery}', NotificationController::class)->defaults('operation', 'delivery');
    Route::post('admin/notification-deliveries/{delivery}/replays', NotificationController::class)->defaults('operation', 'replay');
});
