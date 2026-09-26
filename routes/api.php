<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

require __DIR__.'/identity.php';
require __DIR__.'/intake.php';
require __DIR__.'/documents.php';
require __DIR__.'/commercial.php';
require __DIR__.'/projects.php';
require __DIR__.'/ai.php';
require __DIR__.'/notifications.php';
require __DIR__.'/reporting.php';
require __DIR__.'/admin-integration.php';

Route::get('/', fn () => response()->json(
    ['data' => ['service' => 'HOLOUL', 'api_version' => 'v1']],
    headers: ['Cache-Control' => 'no-store'],
));
