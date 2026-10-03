<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Configuration\AIConfiguration;
use Illuminate\Support\Facades\Config;

trait GeminiFixture
{
    private function configureGemini(): void
    {
        Config::set([
            'ai.enabled' => true, 'ai.driver' => 'gemini', 'ai.model' => AIConfiguration::GEMINI_MODEL,
            'ai.allowed_providers' => ['gemini'], 'ai.allowed_models' => [AIConfiguration::GEMINI_MODEL],
            'ai.gemini.approved' => true, 'ai.gemini.api_key' => 'synthetic-gemini-key-never-real',
            'ai.gemini.documents_approved' => false,
            'ai.reserved_cost_microusd' => AIConfiguration::GEMINI_RESERVATION,
            'ai.daily_budget_microusd' => 1000000, 'operations.deployment_profile' => 'local-verification',
        ]);
    }

    /** @return array<string,mixed> */
    private function geminiResponse(): array
    {
        return [
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [
                ['text' => json_encode(['description' => 'فكرة مشروع واضحة يراجعها العميل قبل اعتمادها.'], JSON_THROW_ON_ERROR)],
            ]]]],
            'usageMetadata' => ['promptTokenCount' => 101, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 7, 'totalTokenCount' => 128],
            'responseId' => 'synthetic-generation-1',
        ];
    }
}
