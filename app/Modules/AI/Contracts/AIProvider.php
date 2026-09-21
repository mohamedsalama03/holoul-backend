<?php

declare(strict_types=1);

namespace App\Modules\AI\Contracts;

use App\Modules\AI\Data\AIInput;
use App\Modules\AI\Data\AIResult;

interface AIProvider
{
    /** External work only; no database access or business tools. */
    public function generate(AIInput $input): AIResult;
}
