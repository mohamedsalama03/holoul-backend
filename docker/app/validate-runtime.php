<?php

declare(strict_types=1);

use App\Infrastructure\Configuration\ProductionConfiguration;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
try {
    $app->make(Kernel::class)->bootstrap();
    $issues = $app->make(ProductionConfiguration::class)->violations();
} catch (Throwable) {
    $issues = ['runtime_configuration_unavailable'];
}
if ($issues !== []) {
    fwrite(STDERR, json_encode(['event' => 'startup.configuration_rejected', 'violations' => $issues], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
