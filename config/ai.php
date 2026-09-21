<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('HOLOUL_AI_ENABLED', false),
    'driver' => 'sandbox',
    'allowed_providers' => ['sandbox'],
    'model' => 'sandbox-v1',
    'allowed_models' => ['sandbox-v1'],
    'maximum_input_characters' => 20000,
    'maximum_output_bytes' => 32000,
    'connect_timeout_seconds' => 5,
    'timeout_seconds' => 60,
    'concurrent_runs' => 8,
    'concurrent_runs_per_user' => 2,
    'runs_per_user_per_day' => 20,
    'daily_budget_microusd' => 10000000,
    'reserved_cost_microusd' => 1000,
    'prompt_version' => 'holoul-suggestions-v1',
    'schema_version' => 'holoul-suggestions-v1',
];
