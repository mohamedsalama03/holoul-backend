<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Configuration\ProductionConfiguration;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ProductionConfigurationTest extends TestCase
{
    public function test_explicit_local_profile_accepts_only_the_known_synthetic_topology_even_in_production_mode(): void
    {
        $this->local();
        Config::set('app.env', 'production');
        Config::set('database.connections.pgsql.username', 'holoul_app');
        self::assertSame([], app(ProductionConfiguration::class)->violations());
        Config::set('app.url', 'https://customers.example.com');
        Config::set('identity.origin', 'https://customers.example.com');
        Config::set('app.trusted_hosts', ['customers.example.com']);
        self::assertContains('local_profile_scope_violation', app(ProductionConfiguration::class)->violations());
    }

    public function test_real_production_does_not_inherit_local_plaintext_or_sandbox_exemptions(): void
    {
        $this->local();
        Config::set('operations.deployment_profile', 'production');
        $issues = app(ProductionConfiguration::class)->violations();
        foreach (['production_environment_required', 'production_https_required', 'database_verified_tls_required', 'redis_verified_tls_required', 'production_email_required', 'private_infrastructure_required'] as $code) {
            self::assertContains($code, $issues);
        }
        self::assertStringNotContainsString(Config::string('app.key'), json_encode($issues, JSON_THROW_ON_ERROR));
    }

    public function test_explicit_valid_production_configuration_passes_without_external_provider_calls(): void
    {
        $this->production();
        self::assertSame([], app(ProductionConfiguration::class)->violations());
    }

    public function test_secret_paths_are_checked_after_resolving_traversal_and_symlinks(): void
    {
        $this->production();
        $inside = tempnam(storage_path('framework'), 'b8-config-');
        $outside = tempnam(sys_get_temp_dir(), 'b8-config-');
        self::assertIsString($inside);
        self::assertIsString($outside);
        $link = sys_get_temp_dir().'/b8-config-link-'.bin2hex(random_bytes(8));
        try {
            file_put_contents($inside, 'synthetic-placeholder');
            file_put_contents($outside, 'synthetic-placeholder');
            self::assertTrue(symlink($inside, $link));
            foreach ([$inside, '/tmp/../'.ltrim($inside, '/'), $link] as $path) {
                self::assertFileIsReadable($path);
                Config::set('operations.secret_files.app', $path);
                self::assertContains('mounted_secret_required', app(ProductionConfiguration::class)->violations());
            }
            unlink($link);
            self::assertTrue(symlink($outside, $link));
            Config::set('operations.secret_files.app', $link);
            self::assertSame([], app(ProductionConfiguration::class)->violations());
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            unlink($inside);
            unlink($outside);
        }
    }

    public function test_readable_ca_filename_cannot_inject_a_postgresql_dsn_option(): void
    {
        $this->production();
        $path = sys_get_temp_dir().'/b8-config-ca-'.bin2hex(random_bytes(8)).';sslmode=disable';
        try {
            self::assertTrue(copy('/run/holoul-storage/client-ca.crt', $path));
            self::assertFileIsReadable($path);
            Config::set('database.connections.pgsql.sslrootcert', $path);
            self::assertContains('database_verified_tls_required', app(ProductionConfiguration::class)->violations());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_testing_mode_only_bypasses_startup_validation_in_the_explicit_local_profile(): void
    {
        foreach (['local-verification' => 17, 'production' => 1] as $profile => $expected) {
            $process = new Process(['sh', base_path('docker/app/entrypoint.sh'), PHP_BINARY, '-r', 'exit(17);'], base_path(),
                ['APP_ENV' => 'testing', 'HOLOUL_DEPLOYMENT_PROFILE' => $profile]);
            $process->setTimeout(15);
            self::assertSame($expected, $process->run());
            if ($profile === 'production') {
                self::assertStringContainsString('startup.configuration_rejected', $process->getErrorOutput());
                self::assertStringContainsString('production_environment_required', $process->getErrorOutput());
            }
        }
    }

    #[DataProvider('unsafeProductionSettings')]
    public function test_unsafe_production_configuration_is_rejected_with_stable_redacted_codes(string $key, mixed $value, string $expected): void
    {
        $this->production();
        Config::set($key, $value);
        $issues = app(ProductionConfiguration::class)->violations();
        self::assertContains($expected, $issues);
        foreach ($issues as $issue) {
            self::assertMatchesRegularExpression('/\A[a-z][a-z0-9_]+\z/D', $issue);
        }
    }

    public static function unsafeProductionSettings(): array
    {
        return [
            ['app.env', 'local', 'production_environment_required'],
            ['operations.debug_requested', true, 'debug_forbidden'],
            ['app.url', 'http://app.example.com', 'https_origin_required'],
            ['app.url', 'https://secret@app.example.com', 'https_origin_required'],
            ['app.url', 'https://app.example.com:0', 'https_origin_required'],
            ['app.trusted_hosts', ['*'], 'exact_trusted_hosts_required'],
            ['app.trusted_proxies', ['*'], 'exact_trusted_proxies_required'],
            ['app.trusted_proxies', ['0.0.0.0/0'], 'exact_trusted_proxies_required'],
            ['identity.origin', 'https://other.example.com', 'csrf_origin_mismatch'],
            ['session.secure', false, 'secure_session_required'],
            ['session.domain', '.example.com', 'secure_session_required'],
            ['database.connections.pgsql.username', 'holoul_migrator', 'runtime_database_role_required'],
            ['database.connections.pgsql.sslmode', 'require', 'database_verified_tls_required'],
            ['database.connections.pgsql.host', '/var/run/postgresql', 'database_verified_tls_required'],
            ['database.connections.pgsql.host', '', 'database_verified_tls_required'],
            ['database.connections.pgsql.host', 'postgres;sslmode=disable', 'database_verified_tls_required'],
            ['database.connections.pgsql.database', "holoul';host=/var/run/postgresql;dbname='holoul", 'database_dsn_configuration_required'],
            ['database.connections.pgsql.database', '', 'database_dsn_configuration_required'],
            ['database.connections.pgsql.port', '5432;host=/var/run/postgresql', 'database_dsn_configuration_required'],
            ['database.connections.pgsql.port', '0', 'database_dsn_configuration_required'],
            ['database.connections.pgsql.port', '65536', 'database_dsn_configuration_required'],
            ['database.redis.default.scheme', 'tcp', 'redis_verified_tls_required'],
            ['database.redis.cache.context.stream.verify_peer', false, 'redis_verified_tls_required'],
            ['database.redis.default.host', 'tcp://redis', 'redis_verified_tls_required'],
            ['database.redis.cache.port', '6379;other', 'redis_verified_tls_required'],
            ['mail.mailers.smtp.host', 'smtp://mail.example.com', 'production_email_required'],
            ['mail.mailers.smtp.port', 0, 'production_email_required'],
            ['operations.private_infrastructure', false, 'private_infrastructure_required'],
            ['operations.edge_rate_limit_enabled', false, 'bounded_edge_limits_required'],
            ['operations.storage_private', false, 'storage_private_required'],
            ['documents.s3.endpoint', 'http://private-storage', 'private_storage_configuration_required'],
            ['documents.s3.endpoint', 'https:', 'private_storage_configuration_required'],
            ['documents.s3.endpoint', 'https://storage.example.com:0', 'private_storage_configuration_required'],
            ['documents.s3.endpoint', 'https://storage.example.com?token=secret', 'private_storage_configuration_required'],
            ['documents.s3.endpoint', 'https://storage.example.com#fragment', 'private_storage_configuration_required'],
            ['operations.inline_secrets', [true], 'inline_secret_forbidden'],
            ['operations.secret_files', ['mail' => '/missing/private-secret'], 'mounted_secret_required'],
            ['queue.connections.notifications.queue', 'default', 'queue_isolation_required'],
            ['queue.connections.ai.retry_after', 30, 'queue_isolation_required'],
            ['documents.max_bytes', 10485761, 'bounded_uploads_required'],
            ['logging.channels.stdout.processors', [], 'safe_logging_required'],
            ['ai.driver', 'unapproved-paid-provider', 'external_ai_not_approved'],
        ];
    }

    private function local(): void
    {
        Config::set('operations.deployment_profile', 'local-verification');
        Config::set('operations.process_role', 'app');
    }

    private function production(): void
    {
        $this->local();
        $ca = '/run/holoul-storage/client-ca.crt';
        self::assertFileIsReadable($ca);
        Config::set([
            'app.env' => 'production', 'app.url' => 'https://app.example.com', 'identity.origin' => 'https://app.example.com',
            'app.trusted_hosts' => ['app.example.com'], 'app.trusted_proxies' => ['10.0.0.4/32'],
            'operations.deployment_profile' => 'production', 'operations.edge_https_enforced' => true,
            'operations.edge_rate_limit_enabled' => true, 'operations.edge_body_limit_bytes' => 12582912,
            'operations.private_infrastructure' => true, 'operations.storage_private' => true,
            'operations.storage_versioned' => true, 'operations.storage_encrypted' => true,
            'operations.secret_files' => array_fill_keys(['app', 'database', 'redis', 'storage_access', 'storage_secret', 'mail'], '/run/holoul-secrets/app_key'),
            'operations.inline_secrets' => [false, false, false, false, false, false],
            'database.connections.pgsql.username' => 'holoul_app', 'database.connections.pgsql.sslmode' => 'verify-full',
            'database.connections.pgsql.sslrootcert' => $ca,
            'database.redis.default.scheme' => 'tls', 'database.redis.cache.scheme' => 'tls',
            'database.redis.default.context.stream.cafile' => $ca, 'database.redis.cache.context.stream.cafile' => $ca,
            'identity.mail_sandbox' => false, 'mail.mailers.smtp.scheme' => 'smtps', 'mail.mailers.smtp.host' => 'mail.example.com',
            'mail.mailers.smtp.username' => 'synthetic-mail-user', 'mail.mailers.smtp.password' => str_repeat('x', 32),
            'mail.from.address' => 'notices@example.com',
        ]);
    }
}
