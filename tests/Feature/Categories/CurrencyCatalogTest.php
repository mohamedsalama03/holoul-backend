<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CurrencyCatalogTest extends TestCase
{
    use DatabaseMigrations;

    public function test_catalog_contains_exactly_the_two_approved_currency_exponents(): void
    {
        self::assertSame(['LYD' => 3, 'USD' => 2], DB::table('currencies')->orderBy('code')->pluck('exponent', 'code')->all());
        self::assertTrue(DB::scalar("SELECT has_table_privilege('holoul_app', 'currencies', 'SELECT')"));
        foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
            self::assertFalse(DB::scalar('SELECT has_table_privilege(?, ?, ?)', ['holoul_app', 'currencies', $privilege]));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function writes(): iterable
    {
        yield 'changed exponent' => ["UPDATE currencies SET exponent = 2 WHERE code = 'LYD'"];
        yield 'removed currency' => ["DELETE FROM currencies WHERE code = 'USD'"];
        yield 'unsupported currency' => ["INSERT INTO currencies(code, exponent) VALUES ('EUR', 2)"];
        yield 'truncate' => ['TRUNCATE currencies CASCADE'];
    }

    #[DataProvider('writes')]
    public function test_catalog_is_immutable_even_for_the_migration_owner(string $sql): void
    {
        $this->expectException(QueryException::class);
        DB::statement($sql);
    }
}
