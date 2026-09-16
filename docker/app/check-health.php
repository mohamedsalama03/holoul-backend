<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';
if ($mode === 'fpm') {
    $socket = @fsockopen('127.0.0.1', 9000, $errorCode, $errorMessage, 1);
    if (is_resource($socket)) {
        fclose($socket);
        exit(0);
    }
    exit(1);
}
$path = match ($mode) {
    'queue' => '/tmp/holoul-queue-heartbeat',
    'scheduler' => '/tmp/holoul-scheduler-heartbeat',
    default => '',
};
$heartbeat = $path !== '' && is_file($path) ? file_get_contents($path) : false;
exit(is_string($heartbeat) && time() - (int) $heartbeat < 120 ? 0 : 1);
