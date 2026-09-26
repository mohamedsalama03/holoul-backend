<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

final class RequestMeasurements
{
    public bool $active = false;

    public int $queries = 0;

    public int $sqlMs = 0;

    public function begin(): void
    {
        $this->active = true;
        $this->queries = 0;
        $this->sqlMs = 0;
    }

    public function query(float $milliseconds): void
    {
        if ($this->active) {
            $this->queries = min(100000, $this->queries + 1);
            $this->sqlMs = min(600000, $this->sqlMs + max(0, (int) $milliseconds));
        }
    }
}
