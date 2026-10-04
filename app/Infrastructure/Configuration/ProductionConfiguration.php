<?php

declare(strict_types=1);

namespace App\Infrastructure\Configuration;

use App\Infrastructure\Logging\SafeLogProcessor;
use Illuminate\Support\Facades\Config;
use Throwable;

/** Configuration assertions complement, but cannot certify, actual infrastructure policy. */
final class ProductionConfiguration
{
    /** @return list<string> Stable codes only: never values, hosts, paths or secret content. */
    public function violations(): array
    {
        try {
            return $this->inspect();
        } catch (Throwable) {
            return ['configuration_invalid_type'];
        }
    }

    /** @return list<string> */
    private function inspect(): array
    {
        $issues = [];
        $profile = Config::string('operations.deployment_profile');
        $local = $profile === 'local-verification';
        $environment = Config::string('app.env');
        $this->require($issues, in_array($profile, ['production', 'local-verification'], true), 'deployment_profile_required');
        $this->require($issues, $local ? in_array($environment, ['local', 'testing', 'production'], true) : $environment === 'production', 'production_environment_required');
        $this->require($issues, ! Config::boolean('app.debug') && ! Config::boolean('operations.debug_requested'), 'debug_forbidden');
        $url = parse_url(Config::string('app.url'));
        $host = is_array($url) && is_string($url['host'] ?? null) ? $url['host'] : '';
        $this->require($issues, is_array($url) && ($url['scheme'] ?? null) === 'https' && $this->networkHost($host)
            && (! isset($url['port']) || $this->tcpPort($url['port']))
            && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment'])
            && (! isset($url['path']) || $url['path'] === '' || $url['path'] === '/'), 'https_origin_required');
        $this->require($issues, Config::string('identity.origin') === Config::string('app.url'), 'csrf_origin_mismatch');
        $hosts = Config::array('app.trusted_hosts');
        $validHosts = $hosts !== [] && in_array($host, $hosts, true);
        foreach ($hosts as $trusted) {
            $validHosts = $validHosts && is_string($trusted) && preg_match('/\A[a-z0-9][a-z0-9.-]{0,252}\z/iD', $trusted) === 1;
        }
        $this->require($issues, $validHosts, 'exact_trusted_hosts_required');
        foreach (Config::array('app.trusted_proxies') as $proxy) {
            $this->require($issues, is_string($proxy) && $this->proxy($proxy), 'exact_trusted_proxies_required');
        }
        $this->require($issues, Config::string('session.driver') === 'database' && Config::string('session.connection') === 'pgsql'
            && Config::string('session.cookie') === '__Host-holoul_session' && Config::boolean('session.secure')
            && Config::boolean('session.http_only') && Config::boolean('session.encrypt') && Config::get('session.domain') === null
            && Config::string('session.path') === '/' && in_array(Config::string('session.same_site'), ['lax', 'strict'], true), 'secure_session_required');
        $key = Config::string('app.key');
        $this->require($issues, str_starts_with($key, 'base64:') && strlen(base64_decode(substr($key, 7), true) ?: '') === 32, 'application_key_required');
        $this->require($issues, Config::string('database.default') === 'pgsql'
            && Config::string('database.connections.pgsql.driver') === 'pgsql'
            && Config::string('database.connections.pgsql.username') !== 'postgres'
            && strlen(Config::string('database.connections.pgsql.password')) >= 16, 'private_database_identity_required');
        $this->require($issues, preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.-]{0,62}\z/D', Config::string('database.connections.pgsql.database')) === 1
            && $this->tcpPort(Config::get('database.connections.pgsql.port')), 'database_dsn_configuration_required');
        if (Config::string('operations.process_role') !== 'migration' && $environment !== 'testing') {
            $this->require($issues, Config::string('database.connections.pgsql.username') === 'holoul_app', 'runtime_database_role_required');
        }
        $this->require($issues, Config::string('cache.default') === 'redis' && Config::string('cache.limiter') === 'redis'
            && strlen(Config::string('database.redis.default.password')) >= 16 && strlen(Config::string('database.redis.cache.password')) >= 16, 'redis_security_required');
        $this->require($issues, Config::string('queue.default') === 'redis' && Config::string('async.queue') === 'default', 'durable_queue_required');
        foreach (['redis' => ['default', 90], 'documents' => ['documents', 180], 'ai' => ['ai', 180], 'notifications' => ['notifications', 90]] as $connection => [$queue, $retry]) {
            $this->require($issues, Config::string('queue.connections.'.$connection.'.driver') === 'redis'
                && Config::string('queue.connections.'.$connection.'.connection') === 'default'
                && Config::string('queue.connections.'.$connection.'.queue') === $queue
                && Config::integer('queue.connections.'.$connection.'.retry_after') === $retry
                && Config::boolean('queue.connections.'.$connection.'.after_commit'), 'queue_isolation_required');
        }
        $this->require($issues, Config::integer('async.lease_seconds') === 60 && Config::integer('async.max_attempts') === 5, 'durable_timing_required');
        $this->require($issues, is_bool(Config::get('documents.uploads_enabled')), 'document_upload_policy_invalid');
        $this->require($issues, Config::integer('documents.max_bytes') > 0 && Config::integer('documents.max_bytes') <= 10485760, 'bounded_uploads_required');
        $endpoint = parse_url(Config::string('documents.s3.endpoint'));
        $this->require($issues, is_array($endpoint) && ($endpoint['scheme'] ?? null) === 'https'
            && is_string($endpoint['host'] ?? null) && $this->networkHost($endpoint['host'])
            && (! isset($endpoint['port']) || $this->tcpPort($endpoint['port']))
            && ! isset($endpoint['user']) && ! isset($endpoint['pass']) && ! isset($endpoint['query']) && ! isset($endpoint['fragment'])
            && strlen(Config::string('documents.s3.secret_key')) >= 16
            && Config::string('documents.s3.access_key') !== '', 'private_storage_configuration_required');
        $this->require($issues, Config::string('logging.default') === 'stdout'
            && in_array(SafeLogProcessor::class, Config::array('logging.channels.stdout.processors'), true), 'safe_logging_required');
        $this->require($issues, AIConfiguration::approved(), 'external_ai_not_approved');
        $this->require($issues, in_array(Config::string('operations.process_role'), ['app', 'default', 'documents', 'ai', 'notifications', 'scheduler', 'migration'], true)
            && Config::integer('operations.worker_slot') >= 1 && Config::integer('operations.worker_slots') <= 64
            && Config::integer('operations.worker_slot') <= Config::integer('operations.worker_slots'), 'bounded_process_identity_required');
        if ($local) {
            $this->require($issues, in_array($host, ['localhost', '127.0.0.1'], true)
                && array_diff(array_values(array_filter($hosts, is_string(...))), ['localhost', '127.0.0.1']) === []
                && Config::string('database.connections.pgsql.host') === 'postgres'
                && Config::string('database.redis.default.host') === 'redis'
                && Config::string('database.redis.cache.host') === 'redis'
                && ($endpoint['host'] ?? null) === 'storage' && Config::boolean('identity.mail_sandbox')
                && Config::string('mail.mailers.smtp.host') === 'mailpit' && Config::integer('mail.mailers.smtp.port') === 1025
                && Config::string('mail.mailers.smtp.scheme') === 'smtp' && Config::string('mail.from.address') === 'noreply@holoul.test',
                'local_profile_scope_violation');
        } else {
            $this->require($issues, ! in_array($host, ['localhost', '127.0.0.1'], true) && Config::boolean('operations.edge_https_enforced'), 'production_https_required');
            $this->require($issues, Config::boolean('operations.edge_rate_limit_enabled')
                && Config::integer('operations.edge_body_limit_bytes') > 0 && Config::integer('operations.edge_body_limit_bytes') <= 12582912, 'bounded_edge_limits_required');
            foreach (['private_infrastructure', 'storage_private', 'storage_versioned', 'storage_encrypted'] as $assertion) {
                $this->require($issues, Config::boolean('operations.'.$assertion), $assertion.'_required');
            }
            $this->require($issues, Config::string('database.connections.pgsql.sslmode') === 'verify-full'
                && $this->networkHost(Config::string('database.connections.pgsql.host'))
                && $this->readableAbsolute(Config::string('database.connections.pgsql.sslrootcert')), 'database_verified_tls_required');
            foreach (['default', 'cache'] as $connection) {
                $this->require($issues, Config::string('database.redis.'.$connection.'.scheme') === 'tls'
                    && $this->networkHost(Config::string('database.redis.'.$connection.'.host'))
                    && $this->tcpPort(Config::get('database.redis.'.$connection.'.port'))
                    && Config::get('database.redis.'.$connection.'.context.stream.verify_peer') === true
                    && Config::get('database.redis.'.$connection.'.context.stream.verify_peer_name') === true
                    && $this->readableAbsolute(Config::string('database.redis.'.$connection.'.context.stream.cafile')), 'redis_verified_tls_required');
            }
            $this->require($issues, ! Config::boolean('identity.mail_sandbox') && Config::string('mail.mailers.smtp.scheme') === 'smtps'
                && $this->networkHost(Config::string('mail.mailers.smtp.host')) && $this->tcpPort(Config::get('mail.mailers.smtp.port'))
                && Config::string('mail.mailers.smtp.username') !== ''
                && strlen(Config::string('mail.mailers.smtp.password')) >= 16
                && filter_var(Config::string('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false
                && Config::string('mail.from.address') !== 'noreply@holoul.test', 'production_email_required');
            $this->require($issues, $this->readableAbsolute(Config::string('documents.s3.ca_bundle')), 'storage_trusted_ca_required');
            foreach (Config::array('operations.secret_files') as $path) {
                $this->require($issues, is_string($path) && $this->mountedSecret($path), 'mounted_secret_required');
            }
            $this->require($issues, ! in_array(true, Config::array('operations.inline_secrets'), true), 'inline_secret_forbidden');
        }

        return array_values(array_unique($issues));
    }

    /** @param list<string> $issues */
    private function require(array &$issues, bool $valid, string $code): void
    {
        if (! $valid) {
            $issues[] = $code;
        }
    }

    private function readableAbsolute(string $path): bool
    {
        // libpq receives CA paths inside a DSN, so delimiters cannot be filenames.
        return str_starts_with($path, '/') && preg_match('/[;\x00-\x1f\x7f]/', $path) !== 1
            && is_file($path) && is_readable($path);
    }

    private function mountedSecret(string $path): bool
    {
        if (! $this->readableAbsolute($path)) {
            return false;
        }
        $resolved = realpath($path);
        $workspace = realpath(base_path());

        return is_string($resolved) && is_string($workspace) && ! str_starts_with($resolved, $workspace.'/');
    }

    private function networkHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        // PostgreSQL socket paths and DSN/URI syntax must not bypass TLS checks.
        return strlen($host) <= 253 && preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\.?\z/iD', $host) === 1;
    }

    private function tcpPort(mixed $port): bool
    {
        return (is_int($port) || (is_string($port) && ctype_digit($port))) && (int) $port >= 1 && (int) $port <= 65535;
    }

    private function proxy(string $proxy): bool
    {
        $parts = explode('/', $proxy, 2);
        if (filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return count($parts) === 1 || (ctype_digit($parts[1]) && (int) $parts[1] > 0 && (int) $parts[1] <= (str_contains($parts[0], ':') ? 128 : 32));
    }
}
