<?php

declare(strict_types=1);

use App\Infrastructure\Configuration\Environment;

return [
    'deployment_profile' => Environment::string('HOLOUL_DEPLOYMENT_PROFILE', 'production'),
    'process_role' => Environment::string('HOLOUL_PROCESS_ROLE', 'app'),
    'worker_slot' => (int) Environment::string('HOLOUL_WORKER_SLOT', '1'),
    'worker_slots' => (int) Environment::string('HOLOUL_WORKER_SLOTS', '1'),
    'debug_requested' => Environment::boolean('APP_DEBUG'),
    'edge_https_enforced' => Environment::boolean('HOLOUL_EDGE_HTTPS_ENFORCED'),
    'edge_rate_limit_enabled' => Environment::boolean('HOLOUL_EDGE_RATE_LIMIT_ENABLED'),
    'edge_body_limit_bytes' => (int) Environment::string('HOLOUL_EDGE_BODY_LIMIT_BYTES', '0'),
    'private_infrastructure' => Environment::boolean('HOLOUL_PRIVATE_INFRASTRUCTURE'),
    'storage_private' => Environment::boolean('HOLOUL_STORAGE_PRIVATE'),
    'storage_versioned' => Environment::boolean('HOLOUL_STORAGE_VERSIONED'),
    'storage_encrypted' => Environment::boolean('HOLOUL_STORAGE_ENCRYPTED'),
    'backup_status_file' => Environment::string('HOLOUL_BACKUP_STATUS_FILE', '/run/holoul-operations/backup-status.json'),
    'restore_status_file' => Environment::string('HOLOUL_RESTORE_STATUS_FILE', '/run/holoul-operations/restore-status.json'),
    'metric_retention_seconds' => 900,
    'slow_query_ms' => 200,
    'secret_files' => array_combine(
        ['app', 'database', 'redis', 'storage_access', 'storage_secret', 'mail'],
        array_map(fn (string $name): string => Environment::string($name.'_FILE'),
            ['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD', 'DOCUMENTS_S3_ACCESS_KEY', 'DOCUMENTS_S3_SECRET_KEY', 'MAIL_PASSWORD']),
    ),
    'inline_secrets' => array_map(fn (string $name): bool => Environment::string($name) !== '',
        ['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD', 'DOCUMENTS_S3_ACCESS_KEY', 'DOCUMENTS_S3_SECRET_KEY', 'MAIL_PASSWORD']),
];
