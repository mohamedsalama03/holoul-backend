<?php

declare(strict_types=1);

namespace App\Modules\AI\Adapters;

use App\Modules\AI\Contracts\AIProvider;
use App\Modules\AI\Data\AIInput;
use App\Modules\AI\Data\AIResult;
use App\Modules\AI\Exceptions\AIProviderFailure;

/** Local deterministic adapter. It performs no network requests, paid work or instruction execution. */
final class SandboxAIProvider implements AIProvider
{
    public function generate(AIInput $input): AIResult
    {
        if ($input->model !== 'sandbox-v1' || $input->timeoutSeconds < 1 || $input->connectTimeoutSeconds < 1) {
            throw new AIProviderFailure('provider_unavailable');
        }
        $description = trim(preg_replace('/\s+/u', ' ', $input->text) ?? '');
        $output = match ($input->purpose) {
            'improve_description' => ['description' => 'Project objective: '.mb_substr($description, 0, 18000)."\n\nConfirm the intended users, expected outcomes, scope and acceptance criteria before implementation."],
            'suggest_category' => $this->category($input),
            'analyze_document' => ['summary' => 'Sandbox analysis of the supplied document: '.mb_substr($description, 0, 5500),
                'findings' => ['Human review is required before adopting any document interpretation.']],
            'extract_requirements' => ['requirements' => [['title' => 'Review requested capability', 'description' => mb_substr($description, 0, 4500), 'priority' => 'should']],
                'questions' => ['Which acceptance criteria confirm successful delivery?']],
            'missing_information' => ['questions' => ['Who are the intended users?', 'What measurable outcome defines success?', 'Which constraints and acceptance criteria apply?']],
            default => throw new AIProviderFailure('unsupported_purpose'),
        };
        $json = json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > $input->maximumOutputBytes) {
            throw new AIProviderFailure('output_limit');
        }

        return new AIResult($json, (int) ceil(mb_strlen($input->text) / 4), (int) ceil(mb_strlen($json) / 4), 0, 'sandbox:'.$input->runId);
    }

    /** @return array{category_id:string,subcategory_id:string,reason:string} */
    private function category(AIInput $input): array
    {
        $choice = $input->taxonomy[0] ?? throw new AIProviderFailure('no_active_taxonomy');

        return ['category_id' => $choice['category_id'], 'subcategory_id' => $choice['subcategory_id'],
            'reason' => 'Deterministic sandbox candidate from the authorized active taxonomy; confirm suitability before applying.'];
    }
}
