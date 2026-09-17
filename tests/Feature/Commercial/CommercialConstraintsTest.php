<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommercialDatabase;
use Tests\Support\CommercialFixtures;
use Tests\TestCase;

final class CommercialConstraintsTest extends TestCase
{
    use CommercialDatabase;
    use CommercialFixtures;

    #[DataProvider('immutableTerms')]
    public function test_issued_terms_are_database_immutable(string $column, mixed $value): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        $this->blocked(fn () => DB::table('proposals')->where('id', $p->id)->update([$column => $value]));
        self::assertSame('issued', $p->refresh()->state);
    }

    public static function immutableTerms(): array
    {
        return [['scope_summary', 'Rewritten'], ['timeline', 'Rewritten'], ['commercial_notes', 'Rewritten'], ['amount_minor', 1],
            ['currency', 'USD'], ['number', 'PROP-2026-999999'], ['valid_until', '2030-01-01T00:00:00Z'], ['content_version', 99], ['state', 'draft']];
    }

    public function test_issued_children_completed_discovery_decisions_and_approvals_cannot_be_changed_or_deleted(): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        foreach (['proposal_items', 'proposal_deliverables'] as $table) {
            $this->blocked(fn () => DB::table($table)->where('proposal_id', $p->id)->delete());
            $this->blocked(fn () => DB::table($table)->where('proposal_id', $p->id)->update(['position' => 50]));
        }
        $this->blocked(fn () => DB::table('proposal_items')->insert(['id' => (string) Str::uuid7(), 'proposal_id' => $p->id, 'position' => 2,
            'title' => 'Late item', 'quantity' => 1, 'unit_price_minor' => 1, 'line_total_minor' => 1]));
        $this->blocked(fn () => DB::table('discovery_revisions')->where('id', $p->discovery_revision_id)->update(['summary' => 'Rewrite']));
        $this->blocked(fn () => DB::table('discovery_requirements')->where('revision_id', $p->discovery_revision_id)->delete());
        $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id);
        foreach (['proposal_approvals', 'proposal_decisions', 'proposal_events', 'proposal_command_keys', 'proposal_contributors', 'discovery_signoffs'] as $table) {
            $this->blocked(fn () => DB::table($table)->delete(), '55000');
            $this->blocked(fn () => DB::statement('TRUNCATE TABLE '.$table.' CASCADE'), '55000');
        }
        $this->blocked(fn () => DB::table('proposals')->where('id', $p->id)->delete());
        $this->blocked(fn () => DB::table('proposal_decisions')->where('proposal_id', $p->id)->update(['reason' => 'Rewrite']), '55000');
    }

    public function test_database_checks_exact_totals_precision_and_completion_independently(): void
    {
        $f = $this->commercialFixture();
        $baseline = $this->completeDiscovery($f['author'], $f['request']);
        $created = $this->commercialCommand($f['author'], $f['request'], 'proposal.create', input: $this->commercialTerms($baseline));
        $id = $created['data']['id'];
        $this->blocked(fn () => DB::table('proposal_items')->where('proposal_id', $id)->update(['line_total_minor' => 1]));
        $this->blocked(fn () => DB::table('proposal_items')->where('proposal_id', $id)->update(['quantity' => 4, 'line_total_minor' => 40492]));
        $this->blocked(fn () => DB::table('proposals')->where('id', $id)->update(['amount_minor' => 1, 'content_version' => 2]));
        $this->blocked(fn () => DB::table('proposal_deliverables')->where('proposal_id', $id)->delete());
        $this->blocked(fn () => DB::table('proposal_items')->where('proposal_id', $id)->delete());
        $this->blocked(fn () => DB::table('proposals')->where('id', $id)->update(['amount_minor' => -1, 'content_version' => 2]));
        $this->blocked(fn () => DB::table('proposals')->where('id', $id)->update(['state' => 'internally_approved']));
        $next = $this->commercialCommand($f['author'], $f['request'], 'discovery.create', input: ['summary' => 'New', 'internal_notes' => 'Private']);
        $this->blocked(fn () => DB::table('discovery_revisions')->where('id', $next['data']['id'])->update(['state' => 'completed', 'completed_at' => now()]));
        $this->blocked(fn () => DB::table('proposals')->where('id', $id)->update(['discovery_revision_id' => $next['data']['id'], 'content_version' => 2]));
    }

    public function test_database_rejects_self_approval_forged_ownership_and_unpaired_commercial_transitions(): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        $other = $this->commercialFixture();
        $this->blocked(fn () => DB::table('proposals')->where('id', $p->id)->update(['customer_id' => $other['request']->customer_id]));
        $this->blocked(fn () => DB::table('proposals')->where('id', $p->id)->update(['state' => 'accepted']));
        $this->blocked(fn () => DB::table('project_requests')->where('id', $f['request']->id)->update(['state' => 'approved']));
        $this->blocked(fn () => DB::table('proposal_decisions')->insert(['id' => (string) Str::uuid7(), 'proposal_id' => $p->id,
            'request_id' => $f['request']->id, 'customer_id' => $f['request']->customer_id, 'decided_by' => $other['customer']->id,
            'decision' => 'accepted', 'proposal_version' => $p->lock_version]), '23503');
        $created = $this->commercialCommand($f['author'], $f['request'], 'proposal.create', input: $this->commercialTerms($p->discovery_revision_id));
        $id = $created['data']['id'];
        $this->blocked(fn () => DB::table('proposal_approvals')->insert(['id' => (string) Str::uuid7(), 'proposal_id' => $id, 'content_version' => 1, 'approved_by' => $f['author']->id]));
        $this->blocked(fn () => DB::table('proposal_approvals')->insert(['id' => (string) Str::uuid7(), 'proposal_id' => $id, 'content_version' => 99, 'approved_by' => $f['approver']->id]));
    }

    public function test_runtime_identity_cannot_change_history_or_run_schema_commands(): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        DB::statement('SET ROLE holoul_app');
        try {
            foreach (['proposal_approvals', 'proposal_decisions', 'proposal_events', 'proposal_command_keys', 'discovery_signoffs'] as $table) {
                self::assertFalse(DB::scalar("SELECT has_table_privilege(current_user, ?, 'UPDATE,DELETE,TRUNCATE')", [$table]));
                $this->blocked(fn () => DB::table($table)->delete(), '42501');
            }
            $this->blocked(fn () => DB::statement('CREATE TABLE commercial_forbidden_ddl(id integer)'), '42501');
            $this->blocked(fn () => DB::table('proposals')->where('id', $p->id)->update(['scope_summary' => 'Runtime rewrite']));
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    public function test_state_history_requires_an_actor_except_for_versioned_system_expiry(): void
    {
        $f = $this->commercialFixture();
        $base = ['id' => (string) Str::uuid7(), 'request_id' => $f['request']->id,
            'from_state' => 'under_review', 'to_state' => 'discovery', 'actor_id' => null];
        $this->blocked(fn () => DB::table('request_state_changes')->insert($base));
        $commercial = [...$base, 'from_state' => 'proposal', 'to_state' => 'approved', 'actor_id' => $f['customer']->id];
        $this->blocked(fn () => DB::table('request_state_changes')->insert($commercial));
        $this->blocked(fn () => DB::table('request_state_changes')->insert([...$commercial, 'entity_version' => 1]));
        $this->blocked(fn () => DB::table('request_state_changes')->insert([...$commercial, 'correlation_id' => (string) Str::uuid7()]));
        $this->blocked(fn () => DB::table('request_state_changes')->insert([...$commercial, 'actor_id' => null,
            'entity_version' => 1, 'correlation_id' => (string) Str::uuid7()]));
    }

    private function blocked(callable $action, string $code = '23514'): void
    {
        try {
            DB::transaction(function () use ($action): void {
                $action();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            self::fail('Database allowed an invalid commercial write.');
        } catch (QueryException|\PDOException $e) {
            self::assertSame($code, (string) $e->getCode());
        }
    }
}
