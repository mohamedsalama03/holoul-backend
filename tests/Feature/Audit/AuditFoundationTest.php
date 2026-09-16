<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Data\SafeAuditMetadata;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class AuditFoundationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_records_a_bounded_event_with_optional_actor_and_utc_timestamp(): void
    {
        $actorId = Str::uuid7()->toString();
        $subjectId = Str::uuid7()->toString();
        $requestId = Str::uuid()->toString();
        $id = (new RecordAuditEvent)->handle(
            eventType: 'foundation.recorded',
            subjectType: 'foundation',
            subjectId: $subjectId,
            requestId: $requestId,
            actorId: $actorId,
            metadata: new SafeAuditMetadata(['outcome' => 'succeeded', 'affected_count' => 1]),
        );

        self::assertTrue(Str::isUuid($id, version: 7));
        $this->assertDatabaseHas('audit_events', [
            'id' => $id,
            'actor_id' => $actorId,
            'event_type' => 'foundation.recorded',
            'subject_type' => 'foundation',
            'subject_id' => $subjectId,
            'request_id' => $requestId,
        ]);
        $row = DB::table('audit_events')->where('id', $id)->first();
        self::assertNotNull($row);
        self::assertEquals(['outcome' => 'succeeded', 'affected_count' => 1], json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(0, (new \DateTimeImmutable($row->occurred_at))->getOffset());

        $anonymousId = $this->recordEvent();
        $this->assertDatabaseHas('audit_events', ['id' => $anonymousId, 'actor_id' => null, 'metadata' => '{}']);
    }

    public function test_audit_insertion_rolls_back_with_the_owning_transaction(): void
    {
        try {
            DB::transaction(function (): void {
                $this->recordEvent();

                throw new RuntimeException('Roll back the owning action.');
            });
        } catch (RuntimeException) {
            // The real transaction must discard the audit event along with its owner.
        }

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_a_failed_audit_insert_aborts_the_owning_transaction(): void
    {
        try {
            DB::transaction(function (): void {
                $this->recordEvent();

                // Simulate an invariant violation at the durable audit boundary.
                DB::table('audit_events')->insert([
                    'id' => Str::uuid7()->toString(),
                    'event_type' => 'foundation.recorded',
                    'subject_type' => 'foundation',
                    'subject_id' => Str::uuid7()->toString(),
                    'request_id' => Str::uuid()->toString(),
                    'occurred_at' => now('UTC'),
                    'metadata' => '{"token":"secret"}',
                ]);
            });

            self::fail('An invalid audit insertion committed.');
        } catch (QueryException $exception) {
            self::assertSame('23514', $exception->errorInfo[0]);
        }

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_fresh_migrations_are_repeatable_and_reinstall_audit_guards(): void
    {
        // DatabaseMigrations already ran migrate:fresh. PostgreSQL keeps a
        // standalone trigger function when that command drops its old table.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $id = $this->recordEvent();

        try {
            DB::statement('DELETE FROM audit_events');
            self::fail('Fresh migration did not restore append-only protection.');
        } catch (QueryException $exception) {
            self::assertSame('55000', $exception->errorInfo[0]);
        }

        $this->assertDatabaseHas('audit_events', ['id' => $id]);
    }

    #[DataProvider('mutations')]
    public function test_database_triggers_reject_direct_history_mutation(string $statement): void
    {
        $id = $this->recordEvent();

        try {
            DB::statement($statement);
            self::fail('The database allowed audit history to change.');
        } catch (QueryException $exception) {
            self::assertSame('55000', $exception->errorInfo[0]);
        }

        $this->assertDatabaseHas('audit_events', ['id' => $id, 'event_type' => 'foundation.recorded']);
        $this->assertDatabaseCount('audit_events', 1);
    }

    /** @return iterable<string, array{string}> */
    public static function mutations(): iterable
    {
        yield 'raw update' => ["UPDATE audit_events SET event_type = 'foundation.changed'"];
        yield 'raw delete' => ['DELETE FROM audit_events'];
        yield 'truncate' => ['TRUNCATE TABLE audit_events'];
    }

    public function test_runtime_role_can_append_and_read_but_has_no_mutation_privileges(): void
    {
        $privileges = DB::selectOne(<<<'SQL'
            SELECT
                has_table_privilege('holoul_app', 'audit_events', 'SELECT') AS can_read,
                has_table_privilege('holoul_app', 'audit_events', 'INSERT') AS can_insert,
                has_table_privilege('holoul_app', 'audit_events', 'UPDATE') AS can_update,
                has_table_privilege('holoul_app', 'audit_events', 'DELETE') AS can_delete,
                has_table_privilege('holoul_app', 'audit_events', 'TRUNCATE') AS can_truncate
            SQL);

        self::assertNotNull($privileges);
        self::assertTrue($privileges->can_read);
        self::assertTrue($privileges->can_insert);
        self::assertFalse($privileges->can_update);
        self::assertFalse($privileges->can_delete);
        self::assertFalse($privileges->can_truncate);

        DB::statement('SET ROLE holoul_app');

        try {
            $id = $this->recordEvent();
            self::assertTrue(DB::table('audit_events')->where('id', $id)->exists());

            try {
                DB::statement('DELETE FROM audit_events');
                self::fail('The runtime role was allowed to delete an audit record.');
            } catch (QueryException $exception) {
                self::assertSame('42501', $exception->errorInfo[0]);
            }
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    #[DataProvider('unsafeDatabaseMetadata')]
    public function test_database_rejects_unsafe_metadata_even_when_the_action_is_bypassed(string $metadata): void
    {
        $this->expectException(QueryException::class);

        DB::table('audit_events')->insert([
            'id' => Str::uuid7()->toString(),
            'actor_id' => null,
            'event_type' => 'foundation.recorded',
            'subject_type' => 'foundation',
            'subject_id' => Str::uuid7()->toString(),
            'request_id' => Str::uuid()->toString(),
            'occurred_at' => now('UTC'),
            'metadata' => $metadata,
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeDatabaseMetadata(): iterable
    {
        yield 'unknown secret field' => ['{"token":"secret"}'];
        yield 'free text outcome' => ['{"outcome":"raw document text"}'];
        yield 'nested object' => ['{"outcome":{"token":"secret"}}'];
        yield 'array' => ['[]'];
        yield 'null' => ['null'];
        yield 'null field' => ['{"outcome":null}'];
        yield 'invalid uuid' => ['{"operation_id":"secret"}'];
        yield 'numeric string' => ['{"attempt_number":"1"}'];
        yield 'fraction' => ['{"attempt_number":1.5}'];
        yield 'attempt bound' => ['{"attempt_number":1001}'];
        yield 'duration bound' => ['{"duration_ms":86400001}'];
        yield 'count bound' => ['{"affected_count":1000001}'];
        yield 'boolean string' => ['{"retryable":"true"}'];
        yield 'size bound' => ['{"outcome":"'.str_repeat('x', 4096).'"}'];
    }

    /** @param array<string, string> $overrides */
    #[DataProvider('invalidReferences')]
    public function test_action_rejects_unbounded_types_and_non_uuid_references(array $overrides): void
    {
        $arguments = array_replace([
            'eventType' => 'foundation.recorded',
            'subjectType' => 'foundation',
            'subjectId' => Str::uuid7()->toString(),
            'requestId' => Str::uuid()->toString(),
            'actorId' => null,
        ], $overrides);

        $this->expectException(InvalidArgumentException::class);

        (new RecordAuditEvent)->handle(...$arguments);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidReferences(): iterable
    {
        yield 'free text type' => [['eventType' => 'private document text']];
        yield 'empty type' => [['eventType' => '']];
        yield 'long type' => [['eventType' => str_repeat('a', 97)]];
        yield 'long subject type' => [['subjectType' => str_repeat('a', 65)]];
        yield 'actor' => [['actorId' => 'someone@example.test']];
        yield 'subject' => [['subjectId' => 'raw-document']];
        yield 'request' => [['requestId' => 'unbounded-correlation-id']];
    }

    private function recordEvent(): string
    {
        return (new RecordAuditEvent)->handle(
            eventType: 'foundation.recorded',
            subjectType: 'foundation',
            subjectId: Str::uuid7()->toString(),
            requestId: Str::uuid()->toString(),
        );
    }
}
