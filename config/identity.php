<?php

declare(strict_types=1);

use App\Infrastructure\Configuration\Environment;

return [
    'origin' => Environment::string('APP_URL', 'https://localhost:8443'),
    'customer_idle_seconds' => 7200,
    'customer_absolute_seconds' => 604800,
    'staff_idle_seconds' => 1800,
    'staff_absolute_seconds' => 43200,
    'recent_password_seconds' => 300,
    'pending_seconds' => 600,
    'mail_sandbox' => Environment::boolean('IDENTITY_MAIL_SANDBOX'),
];
