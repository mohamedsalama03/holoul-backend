<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\AI\Contracts\AIContextVerifier;
use App\Modules\AI\Models\AIRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class AIContextDouble implements AIContextVerifier
{
    public bool $authorized = true;

    public ?Throwable $inputFailure = null;

    public function verify(AIRun $run): void
    {
        if (! $this->authorized) {
            throw new AuthorizationException;
        }
    }

    public function input(AIRun $run): string
    {
        if ($this->inputFailure !== null) {
            throw $this->inputFailure;
        }

        return $run->source_text;
    }

    public function validateTaxonomy(string $categoryId, string $subcategoryId): void
    {
        if (! DB::table('categories')->where('id', $categoryId)->where('active', true)->exists()
            || ! DB::table('subcategories')->where('id', $subcategoryId)->where('category_id', $categoryId)->where('active', true)->exists()) {
            throw new HttpException(422);
        }
    }
}
