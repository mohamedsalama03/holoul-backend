<?php

declare(strict_types=1);

namespace App\Modules\AI\Contracts;

use App\Modules\AI\Models\AIRun;

interface AIContextVerifier
{
    /** Within a transaction: lock identity/parent, authorize and verify exact current source. */
    public function verify(AIRun $run): void;

    /** Outside a transaction: obtain bounded source/extracted text; never a storage URL. */
    public function input(AIRun $run): string;

    public function validateTaxonomy(string $categoryId, string $subcategoryId): void;
}
