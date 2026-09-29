<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Modules\PublicPortfolio\PortfolioPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Normalizer;

final class PortfolioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function operation(): string
    {
        $operation = $this->route('operation');

        return is_string($operation) ? $operation : '';
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $short = 'not_regex:/[\x00-\x1F\x7F]/u';
        $long = 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';
        $text = [
            'title' => ['required', 'string', 'min:2', 'max:120', $short],
            'summary' => ['required', 'string', 'min:10', 'max:240', $short],
            'description' => ['required', 'string', 'min:30', 'max:12000', $long],
            'category_id' => ['required', Rule::in(array_keys(PortfolioPolicy::CATEGORIES))],
        ];

        return match ($this->operation()) {
            'public_list' => ['category' => ['sometimes', Rule::in(array_keys(PortfolioPolicy::CATEGORIES))], 'limit' => ['sometimes', 'integer', 'min:1', 'max:48'], 'cursor' => ['sometimes', 'string', 'max:512']],
            'list' => ['status' => ['sometimes', Rule::in(['draft', 'published'])], 'limit' => ['sometimes', 'integer', 'min:1', 'max:48'], 'cursor' => ['sometimes', 'string', 'max:512']],
            'create' => $text,
            'update' => array_map(static fn (array $rules): array => ['sometimes', ...$rules], $text) + [
                'cover_image_id' => ['sometimes', 'nullable', 'uuid:7'], 'featured_image_id' => ['sometimes', 'nullable', 'uuid:7']],
            'reserve' => ['media_type' => ['required', Rule::in(['image/jpeg', 'image/png', 'image/webp'])],
                'byte_size' => ['required', 'integer', 'min:1', 'max:5242880'], 'sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
                'alt' => ['required', 'string', 'min:1', 'max:240', $short], 'display_order' => ['required', 'integer', 'min:0', 'max:7']],
            'publish' => ['rights_confirmed' => ['required', 'boolean', 'accepted']],
            default => [],
        };
    }

    protected function prepareForValidation(): void
    {
        $rules = $this->rules();
        foreach (array_keys($this->all()) as $field) {
            if (! array_key_exists($field, $rules)) {
                throw ValidationException::withMessages([(string) $field => 'Unknown field.']);
            }
        }
        foreach (['title', 'summary', 'description', 'alt'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $normalized = Normalizer::normalize(str_replace(["\r\n", "\r"], "\n", trim($value)), Normalizer::FORM_C);
                if (! is_string($normalized) || strip_tags($normalized) !== $normalized) {
                    throw ValidationException::withMessages([$field => 'Invalid Unicode.']);
                }
                $this->merge([$field => $normalized]);
            }
        }
        if (is_string($this->input('limit')) && preg_match('/\A[1-9][0-9]?\z/D', $this->string('limit')->toString()) === 1) {
            $this->merge(['limit' => (int) $this->string('limit')->toString()]);
        }
        foreach (['limit', 'byte_size', 'display_order'] as $field) {
            if ($this->exists($field) && ! is_int($this->input($field))) {
                throw ValidationException::withMessages([$field => 'An integer is required.']);
            }
        }
        if ($this->exists('rights_confirmed') && $this->input('rights_confirmed') !== true) {
            throw ValidationException::withMessages(['rights_confirmed' => 'Explicit publication rights confirmation is required.']);
        }
    }
}
