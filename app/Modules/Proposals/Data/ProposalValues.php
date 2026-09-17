<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Data;

use App\Infrastructure\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ProposalValues
{
    /** @param array<string,int|string|CarbonImmutable> $terms
     * @param  list<array{position:int,title:string,description:string,quantity:int,unit_price_minor:int,line_total_minor:int}>  $items
     * @param  list<string>  $deliverables
     */
    private function __construct(public array $terms, public array $items, public array $deliverables) {}

    /** @param array<string,mixed> $input */
    public static function from(array $input): self
    {
        $input['commercial_notes'] ??= '';
        Validator::make($input, [
            'discovery_revision_id' => ['required', 'string', 'uuid'],
            'scope_summary' => ['required', 'string', 'max:20000'], 'timeline' => ['required', 'string', 'max:5000'],
            'commercial_notes' => ['present', 'string', 'max:10000'], 'pricing_mode' => ['required', 'string', 'in:fixed,items'],
            'amount' => ['required', 'string', 'max:24'], 'currency' => ['required', 'string', 'in:USD,LYD'],
            'valid_until' => ['required', 'string', 'date_format:Y-m-d\TH:i:s\Z'],
            'items' => ['present', 'array', 'max:100'], 'items.*' => ['required', 'array:title,description,quantity,unit_price'],
            'items.*.title' => ['required', 'string', 'max:200'], 'items.*.description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'], 'items.*.unit_price' => ['required', 'string', 'max:24'],
            'deliverables' => ['required', 'array', 'min:1', 'max:50'], 'deliverables.*' => ['required', 'string', 'max:5000'],
        ])->validate();
        $currency = self::text($input, 'currency');
        try {
            $amount = Money::parse(self::text($input, 'amount'), $currency);
            $items = $input['items'];
            $deliverables = $input['deliverables'];
            if (! is_array($items) || ! array_is_list($items) || ! is_array($deliverables) || ! array_is_list($deliverables)) {
                throw new HttpException(422);
            }
            $lines = [];
            $total = 0;
            foreach ($items as $index => $item) {
                if (! is_array($item) || ! is_int($item['quantity'] ?? null)) {
                    throw new HttpException(422);
                }
                $quantity = $item['quantity'];
                $item['description'] ??= '';
                $unit = Money::parse(self::text($item, 'unit_price'), $currency)->minorUnits;
                if ($unit > intdiv(PHP_INT_MAX, $quantity)) {
                    throw new InvalidArgumentException('Line total overflow.');
                }
                $line = $unit * $quantity;
                if ($total > PHP_INT_MAX - $line) {
                    throw new InvalidArgumentException('Proposal total overflow.');
                }
                $total += $line;
                $lines[] = ['position' => $index + 1, 'title' => self::text($item, 'title'), 'description' => self::text($item, 'description'),
                    'quantity' => $quantity, 'unit_price_minor' => $unit, 'line_total_minor' => $line];
            }
            $mode = self::text($input, 'pricing_mode');
            if (($mode === 'fixed' && $lines !== []) || ($mode === 'items' && ($lines === [] || $total !== $amount->minorUnits))) {
                throw new InvalidArgumentException('Exact item total does not match amount.');
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }
        $outputs = [];
        foreach ($deliverables as $deliverable) {
            if (! is_string($deliverable)) {
                throw new HttpException(422);
            }
            $outputs[] = $deliverable;
        }

        return new self(['discovery_revision_id' => self::text($input, 'discovery_revision_id'), 'scope_summary' => self::text($input, 'scope_summary'),
            'timeline' => self::text($input, 'timeline'), 'commercial_notes' => self::text($input, 'commercial_notes'), 'pricing_mode' => $mode,
            'amount_minor' => $amount->minorUnits, 'currency' => $currency, 'valid_until' => CarbonImmutable::parse(self::text($input, 'valid_until'))], $lines, $outputs);
    }

    /** @param array<array-key,mixed> $input */
    private static function text(array $input, string $field): string
    {
        return is_string($input[$field] ?? null) ? $input[$field] : throw new HttpException(422);
    }
}
