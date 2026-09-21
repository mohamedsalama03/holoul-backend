<?php

declare(strict_types=1);

namespace App\Modules\AI\Actions;

use App\Modules\AI\Exceptions\AIProviderFailure;
use Illuminate\Support\Str;
use JsonException;

final class AISchema
{
    public const PURPOSES = ['improve_description', 'suggest_category', 'analyze_document', 'extract_requirements', 'missing_information'];

    /** @return array<string,mixed> */
    public function validate(string $purpose, string $json, int $maximumBytes): array
    {
        if (strlen($json) > $maximumBytes || ! mb_check_encoding($json, 'UTF-8')) {
            throw new AIProviderFailure('malformed_output');
        }
        try {
            $output = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AIProviderFailure('malformed_output');
        }
        if (! is_array($output) || array_is_list($output)) {
            throw new AIProviderFailure('malformed_output');
        }
        switch ($purpose) {
            case 'improve_description':
                $this->keys($output, ['description']);
                $this->text($output['description'], 20000);
                break;
            case 'suggest_category':
                $this->keys($output, ['category_id', 'subcategory_id', 'reason']);
                foreach (['category_id', 'subcategory_id'] as $id) {
                    if (! is_string($output[$id]) || ! Str::isUuid($output[$id], 7)) {
                        throw new AIProviderFailure('malformed_output');
                    }
                }
                $this->text($output['reason'], 1000);
                break;
            case 'analyze_document':
                $this->keys($output, ['summary', 'findings']);
                $this->text($output['summary'], 6000);
                $this->texts($output['findings']);
                break;
            case 'extract_requirements':
                $this->keys($output, ['requirements', 'questions']);
                $items = $output['requirements'];
                if (! is_array($items) || ! array_is_list($items) || count($items) < 1 || count($items) > 50) {
                    throw new AIProviderFailure('malformed_output');
                }
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        throw new AIProviderFailure('malformed_output');
                    }
                    $this->keys($item, ['title', 'description', 'priority']);
                    $this->text($item['title'], 200);
                    $this->text($item['description'], 5000);
                    if (! in_array($item['priority'], ['must', 'should', 'could'], true)) {
                        throw new AIProviderFailure('malformed_output');
                    }
                }
                $this->texts($output['questions']);
                break;
            case 'missing_information':
                $this->keys($output, ['questions']);
                $this->texts($output['questions']);
                break;
            default:
                throw new AIProviderFailure('unsupported_purpose');
        }

        // Pretty JSON is a conservative upper bound on PostgreSQL jsonb spacing.
        // Reject before the result writer if canonical storage could exceed its cap.
        if (strlen(json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > min(32000, $maximumBytes)) {
            throw new AIProviderFailure('malformed_output');
        }

        /** @var array<string,mixed> $output */
        return $output;
    }

    /** @param array<array-key,mixed> $value
     * @param  list<string>  $keys
     */
    private function keys(array $value, array $keys): void
    {
        if (count($value) !== count($keys) || array_diff(array_keys($value), $keys) !== [] || array_diff($keys, array_keys($value)) !== []) {
            throw new AIProviderFailure('malformed_output');
        }
    }

    private function text(mixed $value, int $limit): void
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $limit || str_contains($value, "\0")) {
            throw new AIProviderFailure('malformed_output');
        }
    }

    private function texts(mixed $value): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 20) {
            throw new AIProviderFailure('malformed_output');
        }
        foreach ($value as $text) {
            $this->text($text, 1000);
        }
    }
}
