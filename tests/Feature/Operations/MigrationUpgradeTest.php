<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Application\AI\AISources;
use App\Infrastructure\Async\OperationRunner;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\AI\Actions\AIDecisionReceipts;
use App\Modules\AI\Actions\AIRuns;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Actions\ReadActiveIdentity;
use App\Modules\Identity\Contracts\IdentityReader;
use App\Modules\Notifications\Actions\NotificationAccess;
use App\Modules\Notifications\Contracts\EmailProvider;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Projects\Actions\ProjectDocuments;
use App\Modules\Projects\Actions\ProjectStore;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDOException;
use Tests\Support\DocumentFixtures;
use Tests\Support\NotificationProviderDouble;
use Tests\Support\PreUsernameIdentityReader;
use Tests\Support\ProjectFixtures;
use Tests\TestCase;

final class MigrationUpgradeTest extends TestCase
{
    use DocumentFixtures;
    use ProjectFixtures;

    public function test_exact_b7_upgrade_preserves_all_domain_ai_notification_history_and_runtime_guards(): void
    {
        self::assertSame('holoul_test', DB::connection()->getDatabaseName());
        $hashes = json_decode(file_get_contents(base_path('tests/Fixtures/b7-migrations.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(27, $hashes);
        foreach ($hashes as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', base_path($file)), $file);
        }
        $this->artisan('migrate:fresh', ['--path' => array_keys($hashes), '--force' => true])->assertExitCode(0);
        $this->app->instance(IdentityReader::class, new PreUsernameIdentityReader);
        try {
            self::assertTrue(Schema::hasTable('ai_runs'));
            self::assertTrue(Schema::hasTable('notifications'));
            $this->initializeDocuments();
            Queue::fake();
            Config::set('ai.enabled', true);
            $provider = new NotificationProviderDouble;
            app()->instance(EmailProvider::class, $provider);
            $fixture = $this->deliveryBaseline();
            $sourceRequest = $this->createSubmitted($fixture['customer']);
            $run = DB::transaction(function () use ($fixture, $sourceRequest) {
                $identity = app(ReadActiveIdentity::class)->locked($fixture['author']->id);
                $source = app(AISources::class)->capture($identity, 'request', $sourceRequest->id,
                    'missing_information', null, (string) Str::uuid7());

                return app(AIRuns::class)->create($source, 'missing_information', (string) Str::uuid7(), (string) Str::uuid7());
            });
            app(OperationRunner::class)->run($run->operation_id);
            self::assertSame('succeeded', $run->fresh()->state);
            $aiFingerprint = app(AIDecisionReceipts::class)->fingerprint('dismiss', $run->id,
                VersionPrecondition::etag($run->id, $run->fresh()->lock_version), (string) Str::uuid7(), []);
            $aiReceipt = DB::transaction(function () use ($run, $fixture, $aiFingerprint): array {
                $actor = app(ReadActiveIdentity::class)->locked($fixture['author']->id);
                app(AISources::class)->access($actor, $run->parent_type, $run->parent_id);
                $dismissed = app(AIRuns::class)->dismiss($run->refresh(), $actor->id, (string) Str::uuid7());

                return app(AIDecisionReceipts::class)->record($actor->id, 'dismiss', $aiFingerprint, $dismissed);
            });
            $delivery = NotificationDelivery::query()->where('state', 'pending')->firstOrFail();
            $provider->mode = 'uncertain';
            app(OperationRunner::class)->run($delivery->operation_id);
            self::assertSame('uncertain', $delivery->refresh()->state);
            $replayInput = ['reason' => 'Synthetic operator confirmed non-acceptance before the upgrade.', 'resolution' => 'confirmed_not_accepted'];
            $replayEtag = VersionPrecondition::etag($delivery->id, $delivery->lock_version);
            $replayKey = (string) Str::uuid7();
            $notificationReceipt = DB::transaction(fn () => app(NotificationAccess::class)->handle(
                app(ReadActiveIdentity::class)->locked($fixture['author']->id), 'replay', $delivery->id,
                $replayInput, $replayEtag, $replayKey, (string) Str::uuid7()));
            $provider->mode = 'accepted';
            foreach (DB::table('async_operations')->where('kind', 'notifications.email')->pluck('id') as $operation) {
                app(OperationRunner::class)->run($operation);
            }
            self::assertSame('accepted', $delivery->refresh()->state);
            self::assertSame(['uncertain', 'accepted'], DB::table('notification_delivery_attempts')->where('delivery_id', $delivery->id)
                ->orderBy('generation')->pluck('state')->all());
            $preferenceKey = (string) Str::uuid7();
            $preferenceEtag = VersionPrecondition::etag($fixture['customer']->id, 1);
            $preferenceReceipt = DB::transaction(fn () => app(NotificationAccess::class)->handle(
                app(ReadActiveIdentity::class)->locked($fixture['customer']->id), 'preferences_update', '',
                ['workflow_email' => false], $preferenceEtag, $preferenceKey, (string) Str::uuid7()));
            foreach (['ai_runs', 'ai_suggestions', 'ai_provider_attempts', 'ai_budget_days', 'notifications',
                'ai_command_keys', 'notification_deliveries', 'notification_delivery_attempts', 'notification_preferences',
                'notification_replays', 'notification_command_keys'] as $table) {
                self::assertGreaterThan(0, DB::table($table)->count(), $table.' must be non-vacuous');
            }
            $tables = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"), 'tablename');
            $before = [];
            $columns = [];
            $grants = [];
            $constraints = [];
            foreach ($tables as $table) {
                $columns[$table] = Schema::getColumnListing($table);
                $before[$table] = $this->snapshot($table, $columns[$table]);
                $grants[$table] = $this->runtimeGrants($table);
                $constraints[$table] = $this->constraints($table);
            }
            $permissions = DB::table('permissions')->orderBy('id')->get()->all();
            $roleGrants = DB::table('role_permissions')->get()->all();
            $migrations = DB::table('migrations')->orderBy('id')->get()->all();
            $sequences = [];
            foreach (DB::select("SELECT sequencename FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename") as $sequence) {
                $sequences[$sequence->sequencename] = DB::selectOne('SELECT last_value,is_called FROM '.$sequence->sequencename);
            }
            $this->assertDatabaseCount('migrations', 27);
            $this->assertDatabaseCount('permissions', 50);

            $this->artisan('migrate', ['--path' => array_values(array_filter(glob(database_path('migrations/*.php')), static fn (string $file): bool => basename($file) < '2026_09_26')), '--realpath' => true, '--force' => true])->assertExitCode(0);
            $this->assertDatabaseCount('migrations', 29);
            $this->assertDatabaseCount('permissions', 53);
            foreach (array_diff($tables, ['migrations', 'permissions', 'role_permissions']) as $table) {
                self::assertSame($columns[$table], Schema::getColumnListing($table), $table.' columns');
                self::assertSame($before[$table], $this->snapshot($table, $columns[$table]), $table.' rows');
            }
            foreach ($tables as $table) {
                self::assertEquals($grants[$table], $this->runtimeGrants($table), $table.' runtime grants');
                self::assertEquals($constraints[$table], $this->constraints($table), $table.' constraints and triggers');
            }
            foreach ($permissions as $row) {
                $this->assertDatabaseHas('permissions', (array) $row);
            }
            foreach ($roleGrants as $row) {
                $this->assertDatabaseHas('role_permissions', (array) $row);
            }
            foreach ($migrations as $row) {
                $this->assertDatabaseHas('migrations', (array) $row);
            }
            foreach ($sequences as $name => $sequence) {
                if ($name !== 'migrations_id_seq') {
                    self::assertEquals($sequence, DB::selectOne('SELECT last_value,is_called FROM '.$name), $name);
                }
            }

            $allTables = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"), 'tablename');
            $after = [];
            foreach ($allTables as $table) {
                $after[$table] = $this->snapshot($table, Schema::getColumnListing($table));
            }
            $this->artisan('migrate', ['--path' => array_values(array_filter(glob(database_path('migrations/*.php')), static fn (string $file): bool => basename($file) < '2026_09_26')), '--realpath' => true, '--force' => true])->assertExitCode(0);
            foreach ($after as $table => $rows) {
                self::assertSame($rows, $this->snapshot($table, Schema::getColumnListing($table)), $table.' repeat migration');
            }
            $this->assertPreservedHistory($fixture);
            $this->assertRuntimeGuards();
            self::assertSame($aiReceipt, app(AIDecisionReceipts::class)->replay($fixture['author']->id, 'dismiss', $run->id, $aiFingerprint));
            self::assertEquals($notificationReceipt, DB::transaction(fn () => app(NotificationAccess::class)->handle(
                app(ReadActiveIdentity::class)->locked($fixture['author']->id), 'replay', $delivery->id,
                $replayInput, $replayEtag, $replayKey, (string) Str::uuid7())));
            self::assertEquals($preferenceReceipt, DB::transaction(fn () => app(NotificationAccess::class)->handle(
                app(ReadActiveIdentity::class)->locked($fixture['customer']->id), 'preferences_update', '',
                ['workflow_email' => false], $preferenceEtag, $preferenceKey, (string) Str::uuid7())));
            foreach ($after as $table => $rows) {
                self::assertSame($rows, $this->snapshot($table, Schema::getColumnListing($table)), $table.' rejected writes');
            }
            $this->assertDatabaseHas('ai_suggestions', ['run_id' => $run->id, 'state' => 'dismissed']);
            $this->blocked(fn () => DB::table('ai_provider_attempts')->delete(), '23514');
            $this->blocked(fn () => DB::table('notification_delivery_attempts')->delete(), '23514');
            // Newly migrated reporting permissions are additive; the next domain
            // mutation still records its normal notification and immutable audit.
            $this->projectCommand($fixture['author'], $fixture['project'], 'project.update.publish',
                input: ['content' => 'First explicitly published update after the B7 upgrade.']);
            $this->assertDatabaseHas('notifications', ['recipient_id' => $fixture['customer']->id,
                'resource_id' => $fixture['project']->id, 'type' => 'project.update_published']);
        } finally {
            $this->app->forgetInstance(IdentityReader::class);
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        }
    }

    private function deliveryBaseline(): array
    {
        $fixture = $this->projectFixture();
        $project = $fixture['project'];
        $actor = $this->projectActor($fixture['author']);
        $reservation = DB::transaction(function () use ($project, $actor) {
            $record = app(ProjectStore::class)->find($actor, $project->id);

            return app(ProjectDocuments::class)->reserve($record, $actor, VersionPrecondition::etag($record->id, $record->lock_version),
                'approved-delivery.pdf', strlen(self::DOCUMENT_PDF), hash('sha256', self::DOCUMENT_PDF),
                'customer', (string) Str::uuid7(), (string) Str::uuid7());
        });
        $object = $this->uploadFixture($reservation);
        DB::transaction(function () use ($project, $actor, $reservation, $object): void {
            $record = app(ProjectStore::class)->find($actor, $project->id);
            app(ProjectDocuments::class)->finalize($record, $actor, $reservation->documentId, $object,
                VersionPrecondition::etag($record->id, $record->lock_version), (string) Str::uuid7());
        });
        $document = Document::query()->findOrFail($reservation->documentId);
        app(OperationRunner::class)->run($document->scan_operation_id);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'available']);
        $milestone = $this->projectCommand($fixture['author'], $project, 'project.milestone.create', input: [
            'name' => 'Approved first delivery', 'description' => 'Customer-visible retained milestone.',
            'due_date' => '2026-12-01', 'display_order' => 1, 'customer_visible' => true,
        ]);
        $milestoneId = $milestone['data']['id'];
        $this->projectCommand($fixture['author'], $project, 'project.milestone.start', $milestoneId);
        $this->projectCommand($fixture['author'], $project, 'project.update.publish',
            input: ['content' => 'Approved B6 customer-visible delivery update.']);
        $this->projectCommand($fixture['author'], $project, 'project.evidence',
            input: ['kind' => 'plan_approved', 'summary' => 'B6 plan and project team explicitly approved.']);
        $this->projectCommand($fixture['author'], $project, 'project.advance');

        return [...$fixture, 'document_id' => $document->id, 'milestone_id' => $milestoneId];
    }

    private function assertPreservedHistory(array $fixture): void
    {
        $project = $fixture['project']->refresh();
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'state' => 'design']);
        $this->assertDatabaseHas('proposals', ['id' => $project->accepted_proposal_id, 'state' => 'accepted', 'amount_minor' => 30369, 'currency' => 'LYD']);
        $this->assertDatabaseHas('project_documents', ['project_id' => $project->id, 'document_id' => $fixture['document_id'], 'visibility' => 'customer']);
        $this->assertDatabaseHas('milestones', ['id' => $fixture['milestone_id'], 'state' => 'in_progress', 'customer_visible' => true]);
        foreach (['project_state_changes', 'project_membership_history', 'milestone_changes', 'project_updates', 'project_phase_evidence', 'project_activity', 'project_documents'] as $table) {
            self::assertGreaterThan(0, DB::table($table)->count(), $table.' fixture must not be vacuous');
            $this->blocked(fn () => DB::table($table)->delete(), '55000');
        }
        $this->blocked(fn () => DB::table('projects')->where('id', $project->id)->update([
            'accepted_proposal_id' => (string) Str::uuid7(), 'lock_version' => $project->lock_version + 1,
        ]), '23514');
        $this->blocked(fn () => DB::table('projects')->where('id', $project->id)->update([
            'state' => 'development', 'phase_epoch' => $project->phase_epoch + 1, 'lock_version' => $project->lock_version + 1,
        ]), '23514');
        $this->blocked(fn () => DB::table('documents')->where('id', $fixture['document_id'])->update(['state' => 'deleting']), '23514');
        $this->blocked(fn () => DB::table('proposals')->where('id', $project->accepted_proposal_id)->update(['scope_summary' => 'Rewrite']), '23514');
        $this->blocked(fn () => DB::table('proposal_decisions')->delete(), '55000');
        $this->blocked(fn () => DB::table('request_revisions')->update(['project_description' => 'Rewrite']), '55000');
    }

    private function assertRuntimeGuards(): void
    {
        self::assertFalse(DB::scalar("SELECT has_schema_privilege('holoul_app','public','CREATE')"));
        self::assertFalse(DB::scalar("SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolbypassrls FROM pg_roles WHERE rolname='holoul_app'"));
        DB::statement('SET ROLE holoul_app');
        try {
            foreach (['audit_events', 'project_activity', 'project_phase_evidence', 'project_documents', 'proposal_decisions',
                'ai_runs', 'ai_suggestions', 'ai_provider_attempts', 'notifications', 'notification_deliveries', 'notification_replays'] as $table) {
                self::assertTrue(DB::scalar("SELECT has_table_privilege(current_user,?,'SELECT')", [$table]));
                self::assertFalse(DB::scalar("SELECT has_table_privilege(current_user,?,'DELETE,TRUNCATE')", [$table]));
                $this->blocked(fn () => DB::table($table)->delete(), '42501');
            }
            $this->blocked(fn () => DB::statement('CREATE TABLE b8_upgrade_forbidden(id integer)'), '42501');
            $this->blocked(fn () => DB::statement('TRUNCATE projects CASCADE'), '42501');
            $this->blocked(fn () => DB::table('permissions')->update(['code' => 'invalid.permission']), '42501');
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    private function snapshot(string $table, array $columns): array
    {
        $rows = DB::table($table)->select($columns)->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
        sort($rows);

        return $rows;
    }

    private function runtimeGrants(string $table): array
    {
        return DB::select("SELECT privilege_type,is_grantable FROM information_schema.role_table_grants
            WHERE grantee='holoul_app' AND table_schema='public' AND table_name=? ORDER BY privilege_type,is_grantable", [$table]);
    }

    private function constraints(string $table): array
    {
        return [DB::select('SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid=CAST(? AS regclass) ORDER BY conname', [$table]),
            DB::select('SELECT tgname,pg_get_triggerdef(oid) AS definition FROM pg_trigger WHERE tgrelid=CAST(? AS regclass) AND NOT tgisinternal ORDER BY tgname', [$table])];
    }

    private function blocked(callable $action, string $code): void
    {
        try {
            DB::transaction($action);
            self::fail('An invalid write passed the retained upgrade guards.');
        } catch (PDOException $failure) {
            self::assertSame($code, $failure->getCode());
        }
    }
}
