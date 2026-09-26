<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Infrastructure\Operations\MetricRecorder;
use App\Modules\AI\Contracts\AIProvider;
use App\Modules\AI\Data\AIInput;
use App\Modules\AI\Data\AIResult;

final readonly class ObservedAIProvider implements AIProvider
{
    public function __construct(private AIProvider $inner, private MetricRecorder $metrics) {}

    public function generate(AIInput $input): AIResult
    {
        return $this->metrics->provider('ai', fn (): AIResult => $this->inner->generate($input));
    }
}
