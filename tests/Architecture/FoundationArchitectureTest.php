<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class FoundationArchitectureTest extends TestCase
{
    /** @var array<string, list<string>> */
    private const MODULE_DEPENDENCIES = [
        'Identity' => ['Audit'],
        'Customers' => ['Identity', 'Audit'],
        'Categories' => ['Audit'],
        'ProjectIntake' => ['Customers', 'Categories', 'Documents', 'Audit'],
        'Documents' => ['Audit'],
        'AI' => ['Documents', 'Audit'],
        'Discovery' => ['ProjectIntake', 'Documents', 'Audit'],
        'Proposals' => ['ProjectIntake', 'Discovery', 'Documents', 'Audit'],
        'Projects' => ['ProjectIntake', 'Proposals', 'Documents', 'Audit'],
        'Notifications' => ['Identity', 'Customers', 'Audit'],
        'Administration' => ['Identity', 'Customers', 'Categories', 'ProjectIntake', 'Documents', 'AI', 'Discovery', 'Proposals', 'Projects', 'Notifications', 'Audit', 'Reporting'],
        'Audit' => [],
        'Reporting' => ['Identity', 'Customers', 'Categories', 'ProjectIntake', 'Documents', 'AI', 'Discovery', 'Proposals', 'Projects', 'Notifications', 'Administration', 'Audit'],
    ];

    /** @var list<string> */
    private const INFRASTRUCTURE_TABLES = ['audit_events', 'async_operations', 'failed_jobs', 'job_batches', 'sessions', 'migrations'];

    /** @var array<string, list<string>> */
    private const CONTRACT_ONLY_DEPENDENCIES = [
        'Discovery' => ['ProjectIntake'],
        'Proposals' => ['ProjectIntake', 'Discovery'],
        'Projects' => ['ProjectIntake', 'Proposals'],
        'Notifications' => ['Identity', 'Customers'],
    ];

    public function test_module_dependencies_follow_the_approved_map(): void
    {
        foreach ($this->phpFiles(app_path('Modules')) as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen(app_path('Modules')) + 1));
            $owner = explode('/', $relative)[0];
            self::assertArrayHasKey($owner, self::MODULE_DEPENDENCIES, $file);

            foreach ($this->names($this->parse($file)) as $name) {
                if (! str_starts_with($name, 'App\\Modules\\')) {
                    continue;
                }

                $parts = explode('\\', $name);
                $dependency = $parts[2];

                if ($dependency === $owner) {
                    continue;
                }

                self::assertContains($dependency, self::MODULE_DEPENDENCIES[$owner], "$owner may not depend on $name ($file)");
                self::assertNotContains('Models', array_slice($parts, 3), "Cross-module models bypass owned entry points: $name ($file)");

                if ($owner === 'Reporting' || in_array($dependency, self::CONTRACT_ONLY_DEPENDENCIES[$owner] ?? [], true)) {
                    self::assertSame('Contracts', $parts[3] ?? null, "$owner accesses $dependency through its approved read/recipient contract ($file)");
                }

                if ($owner === 'Administration') {
                    self::assertSame('Actions', $parts[3] ?? null, "Administration invokes authorized public actions ($file)");
                }
            }
        }
    }

    public function test_shared_infrastructure_does_not_depend_on_business_modules(): void
    {
        foreach ($this->phpFiles(app_path('Infrastructure')) as $file) {
            foreach ($this->names($this->parse($file)) as $name) {
                if (str_starts_with($name, 'App\\Modules\\')) {
                    self::assertTrue(str_starts_with($name, 'App\\Modules\\Audit\\'), "Shared infrastructure must not depend on a business module ($file)");
                }
            }
        }
    }

    public function test_external_provider_clients_are_confined_to_adapters(): void
    {
        $providerPrefixes = ['Aws\\', 'OpenAI', 'Anthropic\\', 'Stripe\\', 'Twilio\\', 'Resend', 'Postmark\\', 'Google\\Cloud\\', 'MicrosoftAzure\\', 'GuzzleHttp\\Client'];

        foreach ($this->phpFiles(app_path()) as $file) {
            if (str_contains(str_replace('\\', '/', $file), '/Adapters/')) {
                continue;
            }

            $nodes = $this->parse($file);

            foreach ($this->names($nodes) as $name) {
                foreach ($providerPrefixes as $prefix) {
                    self::assertFalse(str_starts_with($name, $prefix), "Provider client $name belongs in an adapter ($file)");
                }

                self::assertNotSame('Illuminate\\Support\\Facades\\Http', $name, "External HTTP access belongs in an adapter ($file)");
            }

            foreach ((new NodeFinder)->findInstanceOf($nodes, Expr\FuncCall::class) as $call) {
                if ($call->name instanceof Name) {
                    self::assertFalse(str_starts_with(strtolower($call->name->toString()), 'curl_'), "Direct cURL access belongs in an adapter ($file)");
                }
            }
        }
    }

    public function test_http_controllers_only_coordinate_http_concerns(): void
    {
        foreach ($this->phpFiles(app_path()) as $file) {
            if (! str_contains(str_replace('\\', '/', $file), '/Controllers/')) {
                continue;
            }

            $nodes = $this->parse($file);

            foreach ($this->names($nodes) as $name) {
                self::assertFalse(str_starts_with($name, 'Illuminate\\Database\\'), "Controllers may not execute database logic ($file)");
                self::assertNotContains($name, ['Illuminate\\Support\\Facades\\DB', 'Illuminate\\Support\\Facades\\Redis', 'Illuminate\\Support\\Facades\\Queue'], "Controllers delegate infrastructure work ($file)");
                self::assertFalse(str_contains($name, '\\Models\\'), "Controllers may not access domain models ($file)");
            }

            foreach ((new NodeFinder)->findInstanceOf($nodes, Stmt\ClassMethod::class) as $method) {
                self::assertLessThanOrEqual(8, count($method->stmts ?? []), "Controller {$method->name} should delegate work ($file)");

                foreach ([Stmt\For_::class, Stmt\Foreach_::class, Stmt\While_::class, Stmt\Do_::class] as $loop) {
                    self::assertSame([], (new NodeFinder)->findInstanceOf($method->stmts ?? [], $loop), "Controller iteration belongs in an action/resource ($file)");
                }
            }
        }
    }

    public function test_postgresql_is_the_only_configured_application_database(): void
    {
        self::assertSame('pgsql', config('database.default'));
        $connections = config('database.connections');
        self::assertIsArray($connections);
        self::assertNotEmpty($connections);

        foreach ($connections as $connection) {
            self::assertIsArray($connection);
            self::assertSame('pgsql', $connection['driver'] ?? null);
        }

        foreach ($this->phpFiles(config_path()) as $file) {
            foreach ((new NodeFinder)->findInstanceOf($this->parse($file), String_::class) as $literal) {
                self::assertFalse(str_contains(strtolower($literal->value), 'sqlite'), "SQLite configuration is forbidden ($file)");
            }
        }

        $manifest = json_decode($this->contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        foreach (['require', 'require-dev'] as $section) {
            self::assertIsArray($manifest[$section] ?? []);

            foreach (array_keys($manifest[$section] ?? []) as $package) {
                self::assertFalse(str_contains(strtolower((string) $package), 'sqlite'));
            }
        }

        $xml = simplexml_load_string($this->contents(base_path('phpunit.xml')));
        self::assertNotFalse($xml);

        foreach ($xml->xpath('/phpunit/php/env[@name="DB_CONNECTION"]') ?: [] as $connection) {
            self::assertSame('pgsql', (string) $connection['value']);
        }
    }

    public function test_production_configuration_cannot_enable_debug_from_environment(): void
    {
        $process = new Process([
            PHP_BINARY,
            '-r',
            'require "vendor/autoload.php"; $config = require "config/app.php"; echo json_encode($config["debug"], JSON_THROW_ON_ERROR);',
        ], base_path(), ['APP_ENV' => 'production', 'APP_DEBUG' => 'true'], null, 15);
        $process->mustRun();

        self::assertSame('false', trim($process->getOutput()));
    }

    public function test_b1_contains_no_business_module_implementation(): void
    {
        foreach (array_keys(self::MODULE_DEPENDENCIES) as $module) {
            self::assertDirectoryExists(app_path("Modules/$module"));

            if ($module !== 'Audit') {
                self::assertSame([], $this->phpFiles(app_path("Modules/$module")), "$module implementation belongs to a later approved batch");
            }
        }

        self::assertSame([], $this->phpFiles(app_path('Models')), 'Business Eloquent models belong to a later batch');

        foreach ($this->phpFiles(database_path('migrations')) as $file) {
            $nodes = $this->parse($file);

            foreach ((new NodeFinder)->findInstanceOf($nodes, Expr\StaticCall::class) as $call) {
                if (! $call->class instanceof Name || ! $call->name instanceof Node\Identifier || $call->name->toString() !== 'create') {
                    continue;
                }

                if ($call->class->toString() !== 'Illuminate\\Support\\Facades\\Schema') {
                    continue;
                }

                $argument = $call->args[0] ?? null;
                self::assertInstanceOf(Node\Arg::class, $argument, $file);
                self::assertInstanceOf(String_::class, $argument->value, "Migration table names must be explicit ($file)");
                self::assertContains($argument->value->value, self::INFRASTRUCTURE_TABLES, "Business migration is outside B1 ($file)");
            }

            foreach ((new NodeFinder)->findInstanceOf($nodes, String_::class) as $literal) {
                preg_match_all('/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:public\.)?"?([a-z_][a-z_0-9]*)/i', $literal->value, $tables);

                foreach ($tables[1] as $table) {
                    self::assertContains(strtolower($table), self::INFRASTRUCTURE_TABLES, "Business SQL is outside B1 ($file)");
                }
            }
        }
    }

    public function test_registered_routes_are_limited_to_b1_infrastructure(): void
    {
        $routes = app('router')->getRoutes();
        $actual = [];

        foreach ($routes as $route) {
            self::assertSame(['GET', 'HEAD'], $route->methods());
            $actual[] = $route->uri();
        }

        sort($actual);
        self::assertSame(['api/v1', 'health/live', 'health/ready'], $actual);
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<Stmt> */
    private function parse(string $file): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($this->contents($file));
        self::assertNotNull($nodes, $file);
        $traverser = new NodeTraverser(new NameResolver);

        return $traverser->traverse($nodes);
    }

    /**
     * @param  list<Stmt>  $nodes
     * @return list<string>
     */
    private function names(array $nodes): array
    {
        return array_values(array_unique(array_map(
            static fn (Name $name): string => $name->toString(),
            (new NodeFinder)->findInstanceOf($nodes, Name::class),
        )));
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertNotFalse($contents, $path);

        return $contents;
    }
}
