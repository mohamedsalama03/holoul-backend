<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Infrastructure\Configuration\ProductionConfiguration;
use Illuminate\Console\Command;

final class ValidateConfigurationCommand extends Command
{
    protected $signature = 'operations:validate-config {--json}';

    protected $description = 'Fail closed on unsafe production configuration, emitting stable non-secret codes only.';

    public function handle(ProductionConfiguration $configuration): int
    {
        $issues = $configuration->violations();
        $this->line(json_encode(['valid' => $issues === [], 'violations' => $issues], JSON_THROW_ON_ERROR));

        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }
}
