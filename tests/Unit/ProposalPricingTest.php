<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Proposals\Data\ProposalValues;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ProposalPricingTest extends TestCase
{
    #[DataProvider('invalidPrices')]
    public function test_invalid_or_overflowing_prices_are_rejected(array $changes): void
    {
        try {
            ProposalValues::from(array_replace($this->input(), $changes));
            self::fail('Invalid price accepted.');
        } catch (ValidationException|HttpException $e) {
            self::assertSame(422, $e instanceof HttpException ? $e->getStatusCode() : $e->status);
        }
    }

    public static function invalidPrices(): array
    {
        $line = static fn (mixed $quantity, mixed $price): array => [['title' => 'Line', 'description' => 'Details', 'quantity' => $quantity, 'unit_price' => $price]];

        return [
            [['amount' => 10.123]], [['amount' => '1e3']], [['amount' => '-1.00']], [['amount' => '1.0001']], [['currency' => 'EUR']],
            [['amount' => '9223372036854775.808']], [['amount' => '30.368']], [['items' => []]],
            [['items' => $line(2, '9223372036854775.807')]], [['items' => $line(0, '10.123')]],
            [['items' => $line(1.5, '10.123')]], [['items' => $line('3', '10.123')]], [['items' => $line(1000001, '10.123')]],
            [['items' => $line(3, 10.123)]], [['items' => $line(3, '10.1234')]], [['pricing_mode' => 'fixed']],
            [['items' => [...$line(1, '9223372036854775.807'), ...$line(1, '0.001')]]],
            [['items' => [['title' => 'Line', 'description' => 'Details', 'quantity' => 3, 'unit_price' => '10.123', 'currency' => 'USD']]]],
        ];
    }

    public function test_maximum_bigint_and_zero_are_exact_without_float_rounding(): void
    {
        foreach (['USD' => '92233720368547758.07', 'LYD' => '9223372036854775.807'] as $currency => $amount) {
            $values = ProposalValues::from([...$this->input(), 'pricing_mode' => 'fixed', 'items' => [], 'currency' => $currency, 'amount' => $amount]);
            self::assertSame(PHP_INT_MAX, $values->terms['amount_minor']);
            $zero = ProposalValues::from([...$this->input(), 'pricing_mode' => 'fixed', 'items' => [], 'currency' => $currency, 'amount' => '0']);
            self::assertSame(0, $zero->terms['amount_minor']);
        }
    }

    private function input(): array
    {
        return ['discovery_revision_id' => (string) Str::uuid7(), 'scope_summary' => 'Scope', 'timeline' => 'Timeline', 'commercial_notes' => 'Notes',
            'pricing_mode' => 'items', 'amount' => '30.369', 'currency' => 'LYD', 'valid_until' => '2026-12-01T12:00:00Z',
            'items' => [['title' => 'Line', 'description' => 'Details', 'quantity' => 3, 'unit_price' => '10.123']], 'deliverables' => ['Portal']];
    }
}
