<?php

declare(strict_types=1);

namespace App\Infrastructure\Exceptions;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Exceptions\Handler;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class SafeExceptionHandler extends Handler
{
    /** @param OutputInterface $output */
    public function renderForConsole($output, Throwable $e): void
    {
        $configuration = $this->container->bound(Repository::class)
            ? $this->container->make(Repository::class)
            : null;

        if ($configuration !== null && in_array($configuration->get('app.env'), ['local', 'testing'], true)) {
            parent::renderForConsole($output, $e);

            return;
        }

        // Fail closed even before configuration loads; verbosity must never expose the throwable.
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $errorOutput->writeln('INTERNAL_ERROR: The command could not be completed.', OutputInterface::OUTPUT_RAW);
    }
}
