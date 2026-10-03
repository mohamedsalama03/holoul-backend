<?php

declare(strict_types=1);

namespace App\Infrastructure\Configuration;

use Illuminate\Support\Facades\Config;

/** The external pilot is local-only; selecting a driver is not permission to transmit data. */
final class AIConfiguration
{
    public const GEMINI_MODEL = 'gemini-3.5-flash-lite';

    public const GEMINI_RESERVATION = 100000;

    public static function approved(): bool
    {
        if (Config::string('ai.driver') === 'sandbox') {
            return Config::string('ai.model') === 'sandbox-v1'
                && Config::array('ai.allowed_providers') === ['sandbox']
                && Config::array('ai.allowed_models') === ['sandbox-v1'];
        }

        return Config::string('ai.driver') === 'gemini' && self::geminiApproved();
    }

    public static function geminiApproved(): bool
    {
        $key = Config::string('ai.gemini.api_key');

        return Config::string('operations.deployment_profile') === 'local-verification'
            && Config::string('ai.driver') === 'gemini'
            && Config::string('ai.model') === self::GEMINI_MODEL
            && Config::array('ai.allowed_providers') === ['gemini']
            && Config::array('ai.allowed_models') === [self::GEMINI_MODEL]
            && Config::boolean('ai.gemini.approved')
            && strlen($key) >= 20 && strlen($key) <= 512 && preg_match('/[\x00-\x20\x7f]/', $key) !== 1
            && Config::integer('ai.reserved_cost_microusd') === self::GEMINI_RESERVATION
            && Config::integer('ai.daily_budget_microusd') >= self::GEMINI_RESERVATION
            && Config::integer('ai.daily_budget_microusd') <= 1000000
            && Config::integer('ai.runs_per_user_per_day') >= 1 && Config::integer('ai.runs_per_user_per_day') <= 20
            && Config::integer('ai.concurrent_runs') >= 1 && Config::integer('ai.concurrent_runs') <= 8
            && Config::integer('ai.concurrent_runs_per_user') >= 1 && Config::integer('ai.concurrent_runs_per_user') <= 2
            && Config::integer('ai.maximum_input_characters') <= 20000 && Config::integer('ai.maximum_input_characters') >= 1
            && Config::integer('ai.maximum_output_bytes') <= 32000 && Config::integer('ai.maximum_output_bytes') >= 1
            && Config::integer('ai.connect_timeout_seconds') >= 1 && Config::integer('ai.connect_timeout_seconds') <= 5
            && Config::integer('ai.timeout_seconds') >= 1 && Config::integer('ai.timeout_seconds') <= 60;
    }
}
