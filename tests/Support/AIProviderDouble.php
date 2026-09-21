<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\AI\Contracts\AIProvider;
use App\Modules\AI\Data\AIInput;
use App\Modules\AI\Data\AIResult;
use Closure;
use Throwable;

final class AIProviderDouble implements AIProvider
{
    public array $inputs = [];

    public array $results = [];

    public ?Closure $afterGenerate = null;

    public function generate(AIInput $input): AIResult
    {
        $this->inputs[] = $input;
        if ($this->afterGenerate !== null) {
            ($this->afterGenerate)();
        }
        $result = array_shift($this->results) ?? new AIResult('{"description":"Reviewed suggestion."}', 10, 5, 0, 'sandbox:test');
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
