<?php

declare(strict_types=1);

use App\Infrastructure\Configuration\Environment;

return [
    'default' => 'smtp',
    'mailers' => ['smtp' => [
        'transport' => 'smtp',
        'scheme' => Environment::string('MAIL_SCHEME', 'smtps'),
        'host' => Environment::string('MAIL_HOST'),
        'port' => (int) Environment::string('MAIL_PORT', '465'),
        'username' => Environment::string('MAIL_USERNAME'),
        'password' => Environment::secret('MAIL_PASSWORD'),
        'timeout' => 10,
        'local_domain' => 'holoul',
    ]],
    'from' => ['address' => Environment::string('MAIL_FROM_ADDRESS'), 'name' => 'HOLOUL'],
];
