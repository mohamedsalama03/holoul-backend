<?php

declare(strict_types=1);

use App\Infrastructure\Configuration\Environment;

return [
    'contact_recipient' => Environment::string('HOLOUL_CONTACT_RECIPIENT', 'info@holoul.ly'),
    // Accepted dashboard integration is a deployment gate, never inferred from a configured URL.
    'contact_dashboard_accepted' => Environment::boolean('HOLOUL_CONTACT_DASHBOARD_ACCEPTED', false),
    'contact_production_mail_enabled' => Environment::boolean('HOLOUL_CONTACT_PRODUCTION_MAIL_ENABLED', false),
    // No scheduler or environment override: the owner has not approved a retention period.
    'contact_automatic_redaction' => false,
    'portfolio_processor_socket' => Environment::string('PORTFOLIO_PROCESSOR_SOCKET', '/run/holoul-portfolio/processor.sock'),
];
