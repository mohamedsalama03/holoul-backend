<?php

declare(strict_types=1);

// Laravel's JSON response factory resolves the view service even for API responses.
return [
    'paths' => [],
    'compiled' => storage_path('framework/views'),
];
