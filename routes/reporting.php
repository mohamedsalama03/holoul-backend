<?php

declare(strict_types=1);

use App\Http\Controllers\ReportingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['identity.spa', 'identity.auth'])->group(function (): void {
    foreach (['dashboard', 'requests', 'projects', 'customers'] as $report) {
        Route::get('admin/reports/'.$report, ReportingController::class)->defaults('report', $report);
    }
    Route::get('admin/audit-events', ReportingController::class)->defaults('report', 'audit');
});
