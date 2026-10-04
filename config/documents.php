<?php

declare(strict_types=1);

use App\Infrastructure\Configuration\Environment;
use App\Modules\Documents\DocumentPolicy;

return [
    'uploads_enabled' => Environment::boolean('HOLOUL_DOCUMENT_UPLOADS_ENABLED', true),
    'max_bytes' => DocumentPolicy::MAX_BYTES,
    's3' => [
        'endpoint' => Environment::string('DOCUMENTS_S3_ENDPOINT', 'https://storage:8333'),
        'region' => Environment::string('DOCUMENTS_S3_REGION', 'us-east-1'),
        'bucket' => Environment::string('DOCUMENTS_S3_BUCKET', 'holoul-documents'),
        'access_key' => Environment::secret('DOCUMENTS_S3_ACCESS_KEY'),
        'secret_key' => Environment::secret('DOCUMENTS_S3_SECRET_KEY'),
        'ca_bundle' => Environment::string('DOCUMENTS_S3_CA_BUNDLE', '/run/holoul-storage/client-ca.crt'),
    ],
    'scanner_socket' => Environment::string('DOCUMENTS_SCANNER_SOCKET', '/run/holoul-clamav/clamd.sock'),
    'inspector_socket' => Environment::string('DOCUMENTS_INSPECTOR_SOCKET', '/run/holoul-inspector/inspector.sock'),
    'max_signature_age_seconds' => 172800,
];
