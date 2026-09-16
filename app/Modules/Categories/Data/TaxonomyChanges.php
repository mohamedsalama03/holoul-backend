<?php

declare(strict_types=1);

namespace App\Modules\Categories\Data;

use Illuminate\Validation\ValidationException;
use Normalizer;

final readonly class TaxonomyChanges
{
    public ?string $name;

    public function __construct(?string $name = null, public ?bool $active = null, public ?int $displayOrder = null)
    {
        if ($name === null && $active === null && $displayOrder === null) {
            throw ValidationException::withMessages(['input' => 'At least one taxonomy field is required.']);
        }

        $this->name = $name === null ? null : self::name($name);

        if ($displayOrder !== null && ($displayOrder < 0 || $displayOrder > 1_000_000)) {
            throw ValidationException::withMessages(['display_order' => 'Display order is outside the supported range.']);
        }
    }

    public static function name(string $name): string
    {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_C);
        $normalized = is_string($normalized) ? preg_replace('/\s+/u', ' ', $normalized) : null;
        $normalized = is_string($normalized) ? trim($normalized) : '';

        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > 160 || preg_match('/[\p{C}]/u', $normalized) !== 0) {
            throw ValidationException::withMessages(['name' => 'A bounded taxonomy name is required.']);
        }

        return $normalized;
    }

    public static function slug(string $slug): string
    {
        if (strlen($slug) > 80 || preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', $slug) !== 1) {
            throw ValidationException::withMessages(['slug' => 'A stable lowercase machine key is required.']);
        }

        return $slug;
    }
}
