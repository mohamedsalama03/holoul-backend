<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Infrastructure\Async\OperationRunner;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Contracts\IdentityReader;
use App\Modules\ProjectIntake\Actions\AssignRequest;
use App\Modules\ProjectIntake\Actions\IntakeDocuments;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Actions\TransitionRequest;
use App\Modules\ProjectIntake\Events\RequestChanged;
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

    public function test_exact_approved_b5_upgrades_without_rewriting_commercial_or_document_history(): void
    {
        self::assertSame('holoul_test', DB::connection()->getDatabaseName());
        $hashes = json_decode(file_get_contents(base_path('tests/Fixtures/b5-migrations.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(19, $hashes);
        foreach ($hashes as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', base_path($file)), $file);
        }
        $this->artisan('migrate:fresh', ['--path' => array_keys($hashes), '--force' => true])->assertExitCode(0);
        $this->app->instance(IdentityReader::class, new PreUsernameIdentityReader);
        try {
            self::assertFalse(Schema::hasTable('projects'));
            $this->initializeDocuments();
            // These notification events were introduced in B7. Keep all B5
            // actions and guards real while the B7 tables do not yet exist.
            [$proposalId, $documentId, $discoveryId, $quarantined] = Event::fakeFor(function (): array {
                $baseline = $this->acceptedDocumentBaseline();
                [, $quarantined] = $this->quarantined();

                return [...$baseline, $quarantined];
            }, [RequestChanged::class, ProposalChanged::class, ProjectChanged::class]);
            self::assertSame('quarantined', $quarantined->state->value);
            $tables = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"), 'tablename');
            $before = [];
            $columns = [];
            foreach (array_diff($tables, ['migrations', 'permissions', 'role_permissions']) as $table) {
                $columns[$table] = Schema::getColumnListing($table);
                $before[$table] = $this->snapshot($table, $columns[$table]);
            }
            $permissions = DB::table('permissions')->orderBy('id')->get()->all();
            $grants = DB::table('role_permissions')->get()->all();
            $sequences = [];
            foreach (['request_reference_sequence', 'proposal_reference_sequence'] as $sequence) {
                $sequences[$sequence] = DB::selectOne('SELECT last_value,is_called FROM '.$sequence);
            }
            $this->assertDatabaseCount('migrations', 19);
            $this->assertDatabaseCount('permissions', 29);
            $this->artisan('migrate', ['--path' => array_values(array_filter(glob(database_path('migrations/*.php')), static fn (string $file): bool => basename($file) < '2026_09_26')), '--realpath' => true, '--force' => true])->assertExitCode(0);
            $this->artisan('migrate', ['--path' => array_values(array_filter(glob(database_path('migrations/*.php')), static fn (string $file): bool => basename($file) < '2026_09_26')), '--realpath' => true, '--force' => true])->assertExitCode(0);
            $this->assertDatabaseCount('migrations', count(array_filter(glob(database_path('migrations/*.php')), static fn (string $file): bool => basename($file) < '2026_09_26')));
            $this->assertDatabaseCount('migrations', 29);
            $this->assertDatabaseCount('permissions', 53);
            foreach ($before as $table => $rows) {
                self::assertSame($rows, $this->snapshot($table, $columns[$table]), $table);
            }
            foreach ($sequences as $name => $sequence) {
                self::assertEquals($sequence, DB::selectOne('SELECT last_value,is_called FROM '.$name));
            }
            foreach ($permissions as $row) {
                $this->assertDatabaseHas('permissions', (array) $row);
            }
            foreach ($grants as $row) {
                $this->assertDatabaseHas('role_permissions', (array) $row);
            }
            $this->assertDatabaseHas('proposals', ['id' => $proposalId, 'state' => 'accepted', 'amount_minor' => 30369, 'currency' => 'LYD']);
            $this->assertDatabaseHas('proposal_decisions', ['proposal_id' => $proposalId, 'decision' => 'accepted']);
            $this->assertDatabaseHas('documents', ['id' => $documentId, 'state' => 'available']);
            $this->assertDatabaseHas('documents', ['id' => $quarantined->id, 'state' => 'quarantined']);
            $this->blocked(fn () => DB::table('proposals')->where('id', $proposalId)->update(['scope_summary' => 'Rewrite']), '23514');
            $this->blocked(fn () => DB::table('proposal_items')->where('proposal_id', $proposalId)->update(['description' => 'Rewrite']), '23514');
            $this->blocked(fn () => DB::table('proposal_deliverables')->where('proposal_id', $proposalId)->delete(), '23514');
            $this->blocked(fn () => DB::table('proposal_documents')->where('proposal_id', $proposalId)->delete(), '23514');
            $this->blocked(fn () => DB::table('proposal_approvals')->where('proposal_id', $proposalId)->delete(), '55000');
            $this->blocked(fn () => DB::table('proposal_decisions')->where('proposal_id', $proposalId)->delete(), '55000');
            $this->blocked(fn () => DB::table('discovery_revisions')->where('id', $discoveryId)->update(['summary' => 'Rewrite']), '23514');
            $this->blocked(fn () => DB::table('intake_revision_documents')->delete(), '55000');
            $this->blocked(fn () => DB::table('documents')->where('id', $documentId)->update(['state' => 'deleting']), '23514');
            $this->blocked(fn () => DB::table('request_revisions')->update(['project_description' => 'Rewrite']), '55000');
            DB::statement('SET ROLE holoul_app');
            try {
                $this->blocked(fn () => DB::table('audit_events')->delete(), '42501');
                $this->blocked(fn () => DB::table('proposal_decisions')->delete(), '42501');
                $this->blocked(fn () => DB::table('proposal_approvals')->delete(), '42501');
                $this->blocked(fn () => DB::statement('TRUNCATE proposals CASCADE'), '42501');
                $this->blocked(fn () => DB::statement('CREATE TABLE b6_upgrade_forbidden(id integer)'), '42501');
            } finally {
                DB::statement('RESET ROLE');
            }
            self::assertTrue(Schema::hasTable('projects'));
            $this->assertDatabaseCount('projects', 0);
        } finally {
            $this->app->forgetInstance(IdentityReader::class);
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        }
    }

    private function acceptedDocumentBaseline(): array
    {
        $customer = $this->intakeCustomer();
        $author = $this->intakeStaff('super_admin');
        $approver = $this->intakeStaff('super_admin');
        $actor = $this->intakeActor($customer);
        $request = app(ManageDraft::class)->create($actor, $this->intakeInput(), (string) Str::uuid7());
        $reservation = DB::transaction(function () use ($request, $actor) {
            $record = app(IntakeStore::class)->find($actor, $request->id, true);

            return app(IntakeDocuments::class)->reserve($record, $actor, $this->commercialEtag($record), 'baseline.pdf', strlen(self::DOCUMENT_PDF),
                hash('sha256', self::DOCUMENT_PDF), (string) Str::uuid7(), (string) Str::uuid7());
        });
        $owner = new DocumentOwner($request->id, $request->customer_id, $customer->id, $customer->id, (string) Str::uuid7());
        $object = $this->uploadFixture($reservation);
        DB::transaction(fn () => app(DocumentService::class)->finalize($owner, $reservation->documentId, $object));
        $document = Document::query()->findOrFail($reservation->documentId);
        app(OperationRunner::class)->run($document->scan_operation_id);
        app(SubmitRequest::class)->handle($actor, $request->id, $this->commercialEtag($request->refresh()), (string) Str::uuid7(), (string) Str::uuid7());
        app(AssignRequest::class)->handle($this->intakeActor($author), $request->id, $this->commercialEtag($request->refresh()), $author->id, (string) Str::uuid7());
        foreach (['review', 'discovery'] as $action) {
            app(TransitionRequest::class)->handle($this->intakeActor($author), $request->id, $this->commercialEtag($request->refresh()), $action, null, (string) Str::uuid7());
        }
        $discovery = $this->completeDiscovery($author, $request);
        $proposal = $this->commercialCommand($author, $request, 'proposal.create', input: $this->commercialTerms($discovery));
        $id = $proposal['data']['id'];
        $this->commercialCommand($author, $request, 'proposal.attach_document', $id, ['document_id' => $document->id]);
        $this->commercialCommand($approver, $request, 'proposal.approve', $id);
        $this->commercialCommand($author, $request, 'proposal.issue', $id);
        $this->commercialCommand($customer, $request, 'proposal.accept', $id);

        return [$id, $document->id, $discovery];
    }

    private function snapshot(string $table, array $columns): array
    {
        $rows = DB::table($table)->select($columns)->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
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
