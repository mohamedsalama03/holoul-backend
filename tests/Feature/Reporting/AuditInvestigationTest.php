<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Data\SafeAuditMetadata;
use App\Modules\Audit\Queries\InvestigateAudit;
use App\Modules\Reporting\Data\ReportWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\CommercialDatabase;
use Tests\Support\IdentityHttp;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class AuditInvestigationTest extends TestCase
{
    use CommercialDatabase;
    use IdentityHttp;
    use IntakeFixtures;

    public function test_cursor_preserves_microsecond_timestamp_ties_and_safe_filters_without_content_or_duplicates(): void
    {
        $this->initializeBrowser();
        $staff = $this->intakeStaff('super_admin');
        $this->signIn($staff)->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
        $subject = (string) Str::uuid7();
        $correlation = (string) Str::uuid7();
        $time = now('UTC')->format('Y-m-d').'T08:30:00.123456Z';
        $expected = [];
        for ($i = 0; $i < 5; $i++) {
            $expected[] = $id = (string) Str::uuid7();
            DB::table('audit_events')->insert(['id' => $id, 'actor_id' => $staff->id, 'event_type' => 'test.investigation',
                'subject_type' => 'test.resource', 'subject_id' => $subject, 'request_id' => $correlation, 'occurred_at' => $time,
                'metadata' => (new SafeAuditMetadata(['outcome' => 'succeeded']))->toJson()]);
        }
        $filters = ['event_type' => 'test.investigation', 'actor_id' => $staff->id, 'subject_type' => 'test.resource',
            'subject_id' => $subject, 'request_id' => $correlation, 'limit' => 2];
        $all = [];
        $cursor = null;
        do {
            $response = $this->browser('GET', '/api/v1/admin/audit-events', [...$filters, ...($cursor === null ? [] : ['cursor' => $cursor])])->assertOk();
            self::assertLessThanOrEqual(2, count($response->json('data')));
            foreach ($response->json('data') as $row) {
                $all[] = $row['id'];
                self::assertSame($time, $row['occurred_at']);
                self::assertSame(['id', 'actor_id', 'event_type', 'subject_type', 'subject_id', 'request_id', 'occurred_at'], array_keys($row));
            }
            $previous = $cursor;
            $cursor = $response->json('meta.next_cursor');
        } while ($cursor !== null);
        rsort($expected);
        self::assertSame($expected, $all);
        self::assertCount(5, array_unique($all));
        self::assertIsString($previous);
        $this->browser('GET', '/api/v1/admin/audit-events', [...$filters, 'cursor' => $previous, 'event_type' => 'test.other'])->assertUnprocessable()->assertJsonMissingPath('exception');
        $this->assertDatabaseHas('audit_events', ['event_type' => 'audit.investigation_viewed', 'actor_id' => $staff->id]);
        self::assertSame(5, DB::table('audit_events')->where('event_type', 'test.investigation')->count());
    }

    public function test_audit_window_is_inclusive_utc_days_and_reads_do_not_mutate_history(): void
    {
        $date = now('UTC')->subDay()->format('Y-m-d');
        $id = app(RecordAuditEvent::class)->handle('test.today', 'test.resource', (string) Str::uuid7(), (string) Str::uuid7());
        $window = ReportWindow::fromInput(['from' => $date, 'to' => $date]);
        $result = app(InvestigateAudit::class)->read($window->from, $window->until, ['event_type' => 'test.today']);
        self::assertSame([], $result['data']);
        self::assertNull($result['meta']['next_cursor']);
        try {
            DB::table('audit_events')->where('id', $id)->update(['event_type' => 'test.changed']);
            self::fail('Audit history remains immutable.');
        } catch (\PDOException $error) {
            self::assertSame('55000', $error->errorInfo[0]);
        }
    }
}
