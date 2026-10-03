<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Identity\Contracts\IdentityReader;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Events\RequestChanged;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\Projects\Events\ProjectChanged;
use App\Modules\Proposals\Events\ProposalChanged;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CommercialFixtures;
use Tests\Support\DocumentFixtures;
use Tests\Support\PreUsernameIdentityReader;
use Tests\TestCase;

final class MigrationUpgradeTest extends TestCase
{
    use CommercialFixtures;
    use DocumentFixtures;

    public function test_exact_approved_b4_upgrades_without_rewriting_existing_rows_or_weakening_document_history(): void
    {
        self::assertSame('holoul_test', DB::connection()->getDatabaseName());
        $hashes = json_decode(file_get_contents(base_path('tests/Fixtures/b4-migrations.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(15, $hashes);
        foreach ($hashes as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', base_path($file)), $file);
        }
        $this->artisan('migrate:fresh', ['--path' => array_keys($hashes), '--force' => true])->assertExitCode(0);
        $this->app->instance(IdentityReader::class, new PreUsernameIdentityReader);
        try {
            self::assertFalse(Schema::hasTable('proposals'));
            self::assertFalse(Schema::hasTable('discovery_records'));
            $this->initializeDocuments();
            // Suppress only the B7 notification events while constructing the
            // historical B4 fixture; all earlier domain behavior stays real.
            $reservation = Event::fakeFor(function () {
                $owner = $this->documentOwner();
                $user = User::query()->findOrFail($owner->userId);
                $actor = $this->intakeActor($user);
                $request = ProjectRequest::query()->findOrFail($owner->parentId);
                app(ManageDraft::class)->update($actor, $request->id, $this->commercialEtag($request), $this->intakeInput(), (string) Str::uuid7());
                $reservation = $this->reservation($owner);
                $object = $this->uploadFixture($reservation);
                DB::transaction(fn () => app(DocumentService::class)->finalize($owner, $reservation->documentId, $object));
                DB::table('intake_draft_documents')->insert(['draft_id' => DB::table('request_drafts')->where('request_id', $request->id)->value('id'),
                    'request_id' => $request->id, 'customer_id' => $request->customer_id, 'document_id' => $reservation->documentId]);
                app(SubmitRequest::class)->handle($actor, $request->id, $this->commercialEtag($request->refresh()), (string) Str::uuid7(), (string) Str::uuid7());

                return $reservation;
            }, [RequestChanged::class, ProposalChanged::class, ProjectChanged::class]);
            $tables = ['users', 'customers', 'roles', 'user_roles', 'sessions', 'identity_sessions', 'audit_events', 'async_operations', 'currencies',
                'categories', 'subcategories', 'project_requests', 'request_drafts', 'request_revisions', 'request_assignments', 'information_requests',
                'information_responses', 'information_resolutions', 'request_state_changes', 'intake_submission_keys', 'intake_notification_intents',
                'documents', 'document_quotas', 'document_orphan_objects', 'document_reconciliation_cursors', 'intake_draft_documents', 'intake_revision_documents'];
            $before = [];
            $columns = [];
            foreach ($tables as $table) {
                $columns[$table] = Schema::getColumnListing($table);
                $before[$table] = $this->snapshot($table, $columns[$table]);
            }
            $permissions = DB::table('permissions')->orderBy('id')->get()->all();
            $grants = DB::table('role_permissions')->get()->all();
            $sequence = DB::selectOne('SELECT last_value,is_called FROM request_reference_sequence');
            $this->assertDatabaseCount('migrations', 15);
            $this->assertDatabaseCount('permissions', 17);
            $this->artisan('migrate', ['--path' => array_values(array_filter(glob(database_path('migrations/*.php')), static fn (string $file): bool => basename($file) < '2026_09_26')), '--realpath' => true, '--force' => true])->assertExitCode(0);
            $this->artisan('migrate', ['--path' => array_values(array_filter(glob(database_path('migrations/*.php')), static fn (string $file): bool => basename($file) < '2026_09_26')), '--realpath' => true, '--force' => true])->assertExitCode(0);
            $this->assertDatabaseCount('migrations', 29);
            $this->assertDatabaseCount('permissions', 53);
            foreach ($tables as $table) {
                self::assertSame($before[$table], $this->snapshot($table, $columns[$table]), $table);
            }
            self::assertEquals($sequence, DB::selectOne('SELECT last_value,is_called FROM request_reference_sequence'));
            foreach ($permissions as $row) {
                $this->assertDatabaseHas('permissions', (array) $row);
            }
            foreach ($grants as $row) {
                $this->assertDatabaseHas('role_permissions', (array) $row);
            }
            $this->blocked(fn () => DB::table('intake_revision_documents')->delete(), '55000');
            $this->blocked(fn () => DB::table('documents')->where('id', $reservation->documentId)->update(['state' => 'deleting']), '23514');
            $this->blocked(fn () => DB::table('request_revisions')->update(['project_description' => 'Rewrite']), '55000');
            DB::statement('SET ROLE holoul_app');
            try {
                $this->blocked(fn () => DB::table('audit_events')->delete(), '42501');
                $this->blocked(fn () => DB::table('proposal_decisions')->delete(), '42501');
                $this->blocked(fn () => DB::statement('CREATE TABLE b5_upgrade_forbidden(id integer)'), '42501');
            } finally {
                DB::statement('RESET ROLE');
            }
            self::assertTrue(Schema::hasTable('discovery_revisions'));
            self::assertTrue(Schema::hasTable('proposal_documents'));
        } finally {
            $this->app->forgetInstance(IdentityReader::class);
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        }
    }

    private function snapshot(string $table, array $columns): array
    {
        $rows = DB::table($table)->select($columns)->get()->map(fn (object $r): string => json_encode($r, JSON_THROW_ON_ERROR))->all();
        sort($rows);

        return $rows;
    }

    private function blocked(callable $call, string $code): void
    {
        try {
            DB::transaction($call);
            self::fail('Invalid write passed.');
        } catch (QueryException $e) {
            self::assertSame($code, $e->getCode());
        }
    }
}
