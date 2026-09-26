<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final readonly class ReportWindow
{
    private function __construct(public string $from, public string $until, public string $fromDate, public string $toDate, public int $days) {}

    /** @param array<string,mixed> $input */
    public static function fromInput(array $input): self
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $from = $input['from'] ?? $today->subDays(29)->format('Y-m-d');
        $to = $input['to'] ?? $today->format('Y-m-d');
        if (! is_string($from) || ! is_string($to)) {
            throw ValidationException::withMessages(['from' => 'Use bounded UTC calendar dates.']);
        }
        foreach ([$from, $to] as $date) {
            if (preg_match('/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $date) !== 1) {
                throw ValidationException::withMessages(['from' => 'Use bounded UTC calendar dates.']);
            }
        }
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');
        if ($start === null || $end === null || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to || $end->lessThan($start)
            || $end->greaterThan($today) || $start->diffInDays($end) >= 366) {
            throw ValidationException::withMessages(['to' => 'Choose an ordered range of at most 366 days ending today or earlier.']);
        }

        return new self($start->toIso8601String(), $end->addDay()->toIso8601String(), $from, $to, (int) $start->diffInDays($end) + 1);
    }

    /** @return array{from:string,to:string,timezone:string,days:int} */
    public function toArray(): array
    {
        return ['from' => $this->fromDate, 'to' => $this->toDate, 'timezone' => 'UTC', 'days' => $this->days];
    }
}
