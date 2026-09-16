<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Data;

use App\Infrastructure\Money\Money;
use App\Modules\ProjectIntake\Models\RequestDraft;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Normalizer;

final readonly class DraftValues
{
    public function __construct(
        public ?string $categoryId, public ?string $subcategoryId,
        public ?string $projectName, public ?string $projectDescription,
        public ?bool $budgetUnknown, public ?int $budgetMinor, public ?string $currency,
    ) {}

    /** @param array<string, mixed> $input */
    public static function patch(?RequestDraft $draft, array $input): self
    {
        $base = $draft === null ? [] : [
            'category_id' => $draft->category_id, 'subcategory_id' => $draft->subcategory_id,
            'project_name' => $draft->project_name, 'project_description' => $draft->project_description,
            'budget_unknown' => $draft->budget_unknown,
            'estimated_budget' => $draft->budget_minor !== null && $draft->currency !== null ? Money::fromMinorUnits($draft->budget_minor, $draft->currency)->decimal() : null,
            'currency' => $draft->currency,
        ];
        $values = array_replace($base, $input);
        $category = self::text($values, 'category_id');
        $subcategory = self::text($values, 'subcategory_id');
        if (($category === null) !== ($subcategory === null)) {
            throw ValidationException::withMessages(['subcategory_id' => 'Select a category and subcategory together.']);
        }
        $unknown = $values['budget_unknown'] ?? null;
        if ($unknown !== null && ! is_bool($unknown)) {
            throw ValidationException::withMessages(['budget_unknown' => 'A boolean is required.']);
        }
        $amount = self::text($values, 'estimated_budget');
        $currency = self::text($values, 'currency');
        $minor = null;
        if ($unknown !== false && ($amount !== null || $currency !== null)) {
            throw ValidationException::withMessages(['estimated_budget' => 'Unknown budget has no amount or currency.']);
        }
        if (($amount === null) !== ($currency === null)) {
            throw ValidationException::withMessages(['estimated_budget' => 'Amount and currency must be provided together.']);
        }
        if ($amount !== null && $currency !== null) {
            try {
                $minor = Money::parse($amount, $currency)->minorUnits;
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['estimated_budget' => 'Invalid exact amount.']);
            }
        }

        return new self($category, $subcategory, self::text($values, 'project_name'), self::text($values, 'project_description'), $unknown, $minor, $currency);
    }

    /** @return array<string, bool|int|string|null> */
    public function columns(): array
    {
        return ['category_id' => $this->categoryId, 'subcategory_id' => $this->subcategoryId,
            'project_name' => $this->projectName, 'project_description' => $this->projectDescription,
            'budget_unknown' => $this->budgetUnknown, 'budget_minor' => $this->budgetMinor, 'currency' => $this->currency];
    }

    public function requireComplete(): void
    {
        $missing = [];
        foreach (['category_id' => $this->categoryId, 'subcategory_id' => $this->subcategoryId,
            'project_name' => $this->projectName, 'project_description' => $this->projectDescription,
            'budget_unknown' => $this->budgetUnknown] as $field => $value) {
            if ($value === null) {
                $missing[$field] = 'Required for submission.';
            }
        }
        if ($this->budgetUnknown === false && ($this->budgetMinor === null || $this->currency === null)) {
            $missing['estimated_budget'] = 'Known budget requires an amount.';
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    /** @param array<string,mixed> $values */
    private static function text(array $values, string $field): ?string
    {
        $value = $values[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw ValidationException::withMessages([$field => 'Expected text.']);
        }
        $value = Normalizer::normalize(trim($value), Normalizer::FORM_C);
        if (! is_string($value) || $value === '') {
            throw ValidationException::withMessages([$field => 'Expected text.']);
        }

        return $value;
    }
}
