<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

require __DIR__.'/identity.php';

Route::get('/', fn () => response()->json(
    ['data' => ['service' => 'HOLOUL', 'api_version' => 'v1']],
    headers: ['Cache-Control' => 'no-store'],
));
