<?php

declare(strict_types=1);

use App\Infrastructure\Configuration\AIConfiguration;
use App\Infrastructure\Configuration\Environment;

$driver = Environment::string('HOLOUL_AI_DRIVER', 'sandbox');
$gemini = $driver === 'gemini';

return [
    'enabled' => Environment::boolean('HOLOUL_AI_ENABLED'),
    'driver' => $driver,
    'allowed_providers' => $gemini ? ['gemini'] : ['sandbox'],
    'model' => $gemini ? AIConfiguration::GEMINI_MODEL : 'sandbox-v1',
    'allowed_models' => $gemini ? [AIConfiguration::GEMINI_MODEL] : ['sandbox-v1'],
    'maximum_input_characters' => 20000,
    'maximum_output_bytes' => 32000,
    'connect_timeout_seconds' => 5,
    'timeout_seconds' => 60,
    'concurrent_runs' => 8,
    'concurrent_runs_per_user' => 2,
    'runs_per_user_per_day' => 20,
    'daily_budget_microusd' => $gemini ? 1000000 : 10000000,
    'reserved_cost_microusd' => $gemini ? AIConfiguration::GEMINI_RESERVATION : 1000,
    'prompt_version' => $gemini ? 'holoul-gemini-intake-v2' : 'holoul-suggestions-v1',
    'schema_version' => 'holoul-suggestions-v1',
    'gemini' => [
        'approved' => Environment::boolean('HOLOUL_GEMINI_APPROVED'),
        'documents_approved' => Environment::boolean('HOLOUL_GEMINI_DOCUMENTS_APPROVED'),
        'api_key' => $gemini ? Environment::secret('GEMINI_API_KEY') : '',
    ],
];
