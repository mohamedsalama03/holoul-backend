<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Modules\AI\Data\AISource;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class AIConcurrencyTest extends TestCase
{
    use CommercialDatabase;
    use IntakeFixtures;

    #[DataProvider('admissionLimits')]
    public function test_real_postgresql_admission_races_never_overspend_or_exceed_concurrency(int $budget, int $concurrent, string $failure): void
    {
        $results = $this->race([$this->source(), $this->source()], $budget, $concurrent);
        $states = array_column($results, 'state');
        sort($states);
        self::assertSame(['pending', 'unavailable'], $states);
        self::assertContains($failure, array_column($results, 'failure'));
        self::assertSame(1000, (int) DB::table('ai_budget_days')->sum('reserved_microusd'));
        self::assertSame(1, DB::table('ai_runs')->where('state', 'pending')->count());
    }

    public static function admissionLimits(): array
    {
        return [[1000, 8, 'daily_budget_exhausted'], [1000000, 1, 'concurrent_limit']];
    }

    public function test_parallel_same_key_creates_one_run_and_one_reservation(): void
    {
        $source = $this->source();
        $key = (string) Str::uuid7();
        $results = $this->race([$source, $source], 1000000, 8, $key);
        self::assertSame($results[0]['id'], $results[1]['id']);
        self::assertSame(['pending', 'pending'], array_column($results, 'state'));
        $this->assertDatabaseCount('ai_runs', 1);
        self::assertSame(1000, (int) DB::table('ai_budget_days')->sum('reserved_microusd'));
    }

    private function source(): AISource
    {
        $customer = $this->intakeCustomer();
        $input = $this->intakeInput();
        $request = app(ManageDraft::class)->create($this->intakeActor($customer), $input, (string) Str::uuid7());

        return new AISource($customer->id, $request->customer_id, 'request', $request->id, 'intake_draft',
            DB::table('request_drafts')->where('request_id', $request->id)->value('id'), $request->lock_version,
            hash('sha256', $input['project_description']), $input['project_description']);
    }

    private function race(array $sources, int $budget, int $concurrent, ?string $key = null): array
    {
        $name = 'ai-race-'.Str::random(10);
        $processes = [];
        DB::beginTransaction();
        try {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('holoul.ai.admission',0))");
            foreach ($sources as $index => $source) {
                $input = ['application' => $name.'-'.$index, 'source' => get_object_vars($source), 'budget' => $budget,
                    'concurrent' => $concurrent, 'key' => $key ?? (string) Str::uuid7()];
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/ai-concurrency-worker.php'), base64_encode(json_encode($input, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing'], timeout: 25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'like', $name.'-%')->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        self::fail($process->getErrorOutput().$process->getOutput());
                    }
                }
                usleep(25000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both independent PostgreSQL sessions must block at the admission boundary.');
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            self::assertCount(2, array_unique(array_column($results, 'backend')));

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
