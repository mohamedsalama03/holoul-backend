<?php

declare(strict_types=1);

namespace App\Modules\AI\Data;

final readonly class AIInput
{
    /** @param list<array{category_id:string,subcategory_id:string,category_name:string,subcategory_name:string}> $taxonomy */
    public function __construct(public string $runId, public string $purpose, public string $model, public string $text,
        public array $taxonomy, public int $connectTimeoutSeconds, public int $timeoutSeconds, public int $maximumOutputBytes) {}
}
