<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectIntake;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\ProjectIntake\Actions\AssignRequest;
use App\Modules\ProjectIntake\Queries\ReadIntake;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class IntakeDisplayConcurrencyTest extends TestCase
{
    use CommercialDatabase;
    use IntakeFixtures;

    public function test_display_detail_read_holds_assignment_until_its_transaction_finishes(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $manager = $this->intakeActor($this->intakeStaff('super_admin'));
        $reader = $this->intakeActor($this->intakeStaff('reviewer'));
        $replacement = $this->intakeStaff('reviewer');
        $record = app(AssignRequest::class)->handle($manager, $record->id,
            VersionPrecondition::etag($record->id, $record->lock_version), $reader->id, (string) Str::uuid7());
        $application = 'display-reassign-'.Str::uuid7();
        $program = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            if (!$app->environment('testing')) { throw new LogicException('Testing environment required.'); }
            Illuminate\Support\Facades\DB::select('SELECT set_config(?, ?, false)', ['application_name', $argv[5]]);
            Illuminate\Support\Facades\DB::statement("SET lock_timeout = '12s'");
            $backend = Illuminate\Support\Facades\DB::scalar('SELECT pg_backend_pid()');
            $actor = new App\Modules\ProjectIntake\Data\IntakeActor($argv[1], null, true, ['intake.read', 'intake.read_all', 'intake.assign']);
            $record = $app->make(App\Modules\ProjectIntake\Actions\AssignRequest::class)->handle(
                $actor, $argv[2], $argv[3], $argv[4], (string) Illuminate\Support\Str::uuid7()
            );
            echo json_encode(['backend' => $backend, 'assignee' => $record->assigned_staff_id, 'version' => $record->lock_version], JSON_THROW_ON_ERROR);
            PHP;
        $peer = null;
        $reachedBarrier = false;
        $parentBackend = DB::scalar('SELECT pg_backend_pid()');
        DB::listen(function (QueryExecuted $query) use (&$reachedBarrier, &$peer, $record, $manager, $replacement, $application, $program): void {
            if ($reachedBarrier || ! str_starts_with($query->sql, 'select ') || ! str_contains($query->sql, 'from "project_requests"')) {
                return;
            }
            // QueryExecuted runs after the scoped parent read and before its child
            // queries. Start a real competing assignment at that exact boundary.
            $reachedBarrier = true;
            $peer = new Process([PHP_BINARY, '-r', $program, '--', $manager->id, $record->id,
                VersionPrecondition::etag($record->id, $record->lock_version), $replacement->id, $application], base_path(), timeout: 20);
            $peer->start();
            $deadline = microtime(true) + 8;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', $application)->where('wait_event_type', 'Lock')->count();
                if ($waiting === 1) {
                    break;
                }
                if (! $peer->isRunning()) {
                    self::fail('Reassignment finished before the authorized child read: '.$peer->getErrorOutput().$peer->getOutput());
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertSame(1, $waiting, 'The independent reassignment must wait on the active read transaction.');
        });

        DB::beginTransaction();
        try {
            $detail = app(ReadIntake::class)->detail($reader, $record->id, (string) Str::uuid7(), display: true);
            self::assertTrue($reachedBarrier);
            self::assertSame($reader->id, $detail['assigned_staff_id']);
            self::assertSame(['id' => $reader->id, 'display_name' => 'Intake Staff'], $detail['assigned_staff']);
            self::assertSame($record->customer_id, $detail['customer_id']);
            self::assertSame($record->latest_revision_id, $detail['latest_revision']['id']);
            self::assertArrayNotHasKey('draft', $detail);
            self::assertInstanceOf(Process::class, $peer);
            self::assertTrue($peer->isRunning());
            $this->assertDatabaseHas('project_requests', ['id' => $record->id, 'assigned_staff_id' => $reader->id]);
            DB::commit();

            self::assertSame(0, $peer->wait(), $peer->getErrorOutput());
            $result = json_decode($peer->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertNotSame($parentBackend, $result['backend']);
            self::assertSame($replacement->id, $result['assignee']);
            self::assertSame($record->lock_version + 1, $result['version']);
            $this->assertDatabaseHas('project_requests', ['id' => $record->id, 'assigned_staff_id' => $replacement->id]);
            self::assertSame(1, DB::table('audit_events')->where('subject_id', $record->id)->where('event_type', 'intake.reassigned')->count());

            try {
                DB::transaction(fn () => app(ReadIntake::class)->detail($reader, $record->id, (string) Str::uuid7(), display: true));
                self::fail('The previous assignee retained access after reassignment committed.');
            } catch (HttpException $exception) {
                self::assertSame(404, $exception->getStatusCode());
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($peer instanceof Process && $peer->isRunning()) {
                $peer->stop(0);
            }
        }
    }
}
