<?php

declare(strict_types=1);

return [
    'queue' => 'default',
    // B1 default queue: job timeout 30s < lease 60s < Redis retry_after 90s.
    // Later purpose queues require their own reviewed timeout/retry policy.
    'lease_seconds' => 60,
    'republish_seconds' => 90,
    'max_attempts' => 5,
];
