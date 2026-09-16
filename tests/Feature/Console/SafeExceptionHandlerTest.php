<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Infrastructure\Exceptions\SafeExceptionHandler;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;

final class SafeExceptionHandlerTest extends TestCase
{
    #[DataProvider('verbosityLevels')]
    public function test_production_rendering_withholds_sql_credentials_paths_and_traces_at_every_verbosity(int $verbosity): void
    {
        Config::set('app.env', 'production');
        $handler = app(ExceptionHandler::class);
        self::assertInstanceOf(SafeExceptionHandler::class, $handler);
        $output = new BufferedOutput($verbosity);

        $handler->renderForConsole($output, self::privateException());

        self::assertSame("INTERNAL_ERROR: The command could not be completed.\n", $output->fetch());
    }

    public function test_a_failing_artisan_command_returns_a_safe_error_and_failure_exit_status(): void
    {
        Config::set('app.env', 'production');
        $exception = self::privateException();
        Artisan::command('foundation:test-console-failure', function () use ($exception): never {
            throw $exception;
        });
        $output = new BufferedOutput;
        $environmentVerbosity = getenv('SHELL_VERBOSITY');
        $envVerbosity = $_ENV['SHELL_VERBOSITY'] ?? null;
        $serverVerbosity = $_SERVER['SHELL_VERBOSITY'] ?? null;

        try {
            $status = app(Kernel::class)->handle(new StringInput('foundation:test-console-failure -vvv'), $output);
        } finally {
            $environmentVerbosity === false ? putenv('SHELL_VERBOSITY') : putenv('SHELL_VERBOSITY='.$environmentVerbosity);
            unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);

            if ($envVerbosity !== null) {
                $_ENV['SHELL_VERBOSITY'] = $envVerbosity;
            }

            if ($serverVerbosity !== null) {
                $_SERVER['SHELL_VERBOSITY'] = $serverVerbosity;
            }
        }

        self::assertSame(1, $status);
        self::assertSame(OutputInterface::VERBOSITY_DEBUG, $output->getVerbosity());
        self::assertSame("INTERNAL_ERROR: The command could not be completed.\n", $output->fetch());
    }

    public function test_early_bootstrap_failures_without_configuration_are_safe(): void
    {
        $handler = new SafeExceptionHandler(new Container);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);

        $handler->renderForConsole($output, self::privateException());

        self::assertSame("INTERNAL_ERROR: The command could not be completed.\n", $output->fetch());
    }

    public function test_local_console_diagnostics_remain_available(): void
    {
        Config::set('app.env', 'local');
        $output = new BufferedOutput;

        app(ExceptionHandler::class)->renderForConsole($output, new RuntimeException('local diagnostic marker'));

        self::assertStringContainsString('local diagnostic marker', $output->fetch());
    }

    /** @return array<string, array{int}> */
    public static function verbosityLevels(): array
    {
        return [
            'normal' => [OutputInterface::VERBOSITY_NORMAL],
            'verbose' => [OutputInterface::VERBOSITY_VERBOSE],
            'very verbose' => [OutputInterface::VERBOSITY_VERY_VERBOSE],
            'debug' => [OutputInterface::VERBOSITY_DEBUG],
        ];
    }

    private static function privateException(): QueryException
    {
        return new QueryException(
            'postgres://db-user:private-password@db/private',
            'select * from internal_documents where token = ?',
            ['private-token'],
            new RuntimeException('Credentials unavailable at /private/credentials.txt'),
        );
    }
}
