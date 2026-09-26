<?php

declare(strict_types=1);

use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Mfa\MfaCredential;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

// Read-only inventory on the synthetic, isolated recovery source/target only.
// The driver keeps this detailed hash manifest private; public evidence has counts.
$phase = 'configuration';
try {
    if (PHP_SAPI !== 'cli' || preg_match('/\A[a-f0-9]{24}\z/D', getenv('HOLOUL_RESTORE_DRILL') ?: '') !== 1) {
        throw new RuntimeException;
    }
    require __DIR__.'/../vendor/autoload.php';
    $app = require __DIR__.'/../bootstrap/app.php';
    if (! $app instanceof Application) {
        throw new RuntimeException;
    }
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    if (! $app->environment('production') || Config::boolean('app.debug') || is_file(__DIR__.'/../vendor/bin/phpunit')
        || Config::string('operations.deployment_profile') !== 'local-verification'
        || ! Config::boolean('identity.mail_sandbox') || Config::string('mail.mailers.smtp.host') !== 'mailpit'
        || Config::string('database.connections.pgsql.username') !== 'holoul_app'
        || Config::string('database.connections.pgsql.host') !== 'postgres'
        || Config::string('database.connections.pgsql.database') !== 'holoul'
        || parse_url(Config::string('app.url'), PHP_URL_HOST) !== 'localhost') {
        throw new RuntimeException;
    }
    DB::statement('SET default_transaction_read_only = on');
    $phase = 'synthetic_identities';
    $users = DB::table('users')->pluck('email');
    if ($users->count() !== 4 || $users->contains(fn (string $email): bool => preg_match('/\Ab3-smoke-[a-f0-9]{24}-[a-z-]+@example\.test\z/D', $email) !== 1)) {
        throw new RuntimeException;
    }
    $phase = 'lineage';
    /** @var object{projects: int, baseline_documents: int}|null $lineage */
    $lineage = DB::selectOne("SELECT count(DISTINCT p.id) AS projects, count(DISTINCT d.id) AS baseline_documents
        FROM projects p JOIN project_requests r ON r.id=p.source_request_id AND r.customer_id=p.customer_id
        JOIN customers c ON c.id=r.customer_id AND c.user_id=r.customer_user_id
        JOIN request_revisions rr ON rr.request_id=r.id
        JOIN intake_revision_documents rd ON rd.revision_id=rr.id
        JOIN documents d ON d.id=rd.document_id AND d.customer_id=c.id AND d.state='available'
        JOIN proposals proposal ON proposal.id=p.accepted_proposal_id AND proposal.request_id=r.id AND proposal.state='accepted'
        JOIN proposal_decisions decision ON decision.id=p.accepted_decision_id AND decision.proposal_id=proposal.id AND decision.decision='accepted'
        JOIN proposal_documents pd ON pd.proposal_id=proposal.id AND pd.document_id=d.id
        WHERE r.state='converted' AND p.state='completed'");
    if ($lineage === null || (int) $lineage->projects !== 1 || (int) $lineage->baseline_documents !== 1) {
        throw new RuntimeException;
    }
    $phase = 'history';
    $history = [];
    foreach (['request_revisions', 'request_state_changes', 'proposal_approvals', 'proposal_decisions', 'project_state_changes',
        'project_membership_history', 'milestone_changes', 'project_phase_evidence', 'project_completion_confirmations',
        'project_updates', 'project_activity', 'audit_events'] as $table) {
        $history[$table] = DB::table($table)->count();
        if ($history[$table] < 1) {
            throw new RuntimeException;
        }
    }
    $phase = 'mfa_decryption';
    $mfa = [];
    foreach (MfaCredential::query()->orderBy('id')->get() as $credential) {
        $secret = $credential->secret;
        if (! is_string($secret) || preg_match('/\A[A-Z2-7]{32}\z/D', $secret) !== 1) {
            throw new RuntimeException;
        }
        $mfa[] = hash('sha256', $credential->id.'|'.$secret);
    }
    if (count($mfa) !== 2) {
        throw new RuntimeException;
    }
    $phase = 'private_objects';
    $documents = [];
    foreach (Document::query()->where('state', 'available')->orderBy('id')->get() as $document) {
        $object = $document->object();
        $stream = app(PrivateObjectStore::class)->openVerified($object);
        try {
            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $stream);
            $checksum = hash_final($hash);
            if ($bytes !== $object->size || ! hash_equals($object->sha256, $checksum)) {
                throw new RuntimeException;
            }
            $documents[] = ['id' => $document->id, 'version' => $object->versionId, 'bytes' => $bytes, 'sha256' => $checksum];
        } finally {
            fclose($stream);
        }
    }
    if (count($documents) !== 3) {
        throw new RuntimeException;
    }
    $phase = 'table_hashes';
    $tables = [];
    /** @var list<object{tablename: string}> $databaseTables */
    $databaseTables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename");
    foreach ($databaseTables as $table) {
        $name = $table->tablename;
        if (preg_match('/\A[a-z_]+\z/D', $name) !== 1) {
            throw new RuntimeException;
        }
        $hash = hash_init('sha256');
        $rows = 0;
        /** @var iterable<object{row: string}> $records */
        $records = DB::cursor('SELECT to_jsonb(t)::text AS row FROM "'.$name.'" t ORDER BY to_jsonb(t)::text');
        foreach ($records as $row) {
            hash_update($hash, $row->row."\n");
            $rows++;
        }
        $tables[$name] = ['rows' => $rows, 'sha256' => hash_final($hash)];
    }
    $phase = 'sequences';
    $sequences = [];
    /** @var list<object{sequencename: string}> $databaseSequences */
    $databaseSequences = DB::select("SELECT sequencename FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename");
    foreach ($databaseSequences as $sequence) {
        $name = $sequence->sequencename;
        if (preg_match('/\A[a-z_]+\z/D', $name) !== 1) {
            throw new RuntimeException;
        }
        $sequences[$name] = DB::selectOne('SELECT last_value,is_called FROM "'.$name.'"');
    }
    $phase = 'constraints_and_grants';
    $constraints = DB::select("SELECT con.conrelid::regclass::text AS relation,con.conname,
        pg_get_constraintdef(con.oid) AS definition,con.contype,con.convalidated,con.condeferrable,
        con.condeferred,con.conislocal,con.coninhcount,con.connoinherit,pg_get_userbyid(relation.relowner) AS relation_owner
        FROM pg_constraint con JOIN pg_class relation ON relation.oid=con.conrelid
        WHERE con.connamespace='public'::regnamespace ORDER BY relation,con.conname");
    $triggers = DB::select("SELECT tgrelid::regclass::text AS relation,tgname,pg_get_triggerdef(oid) AS definition
        FROM pg_trigger WHERE NOT tgisinternal AND tgrelid IN (SELECT oid FROM pg_class WHERE relnamespace='public'::regnamespace)
        ORDER BY relation,tgname");
    $grants = DB::select("SELECT table_name,grantee,privilege_type,is_grantable FROM information_schema.role_table_grants
        WHERE table_schema='public' ORDER BY table_name,grantee,privilege_type");
    /** @var list<object{rolname: string, rolsuper: bool, rolcreatedb: bool, rolcreaterole: bool, rolreplication: bool, rolbypassrls: bool}> $roles */
    $roles = DB::select("SELECT rolname,rolsuper,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls FROM pg_roles
        WHERE rolname IN ('holoul_app','holoul_migrator') ORDER BY rolname");
    foreach ($roles as $role) {
        if ($role->rolsuper || $role->rolcreatedb || $role->rolcreaterole || $role->rolreplication || $role->rolbypassrls) {
            throw new RuntimeException;
        }
    }
    if (DB::scalar("SELECT has_table_privilege('holoul_app','audit_events','UPDATE,DELETE,TRUNCATE')")
        || DB::scalar("SELECT has_schema_privilege('holoul_app','public','CREATE')")) {
        throw new RuntimeException;
    }
    echo json_encode(['ok' => true, 'tables' => $tables, 'sequences' => $sequences, 'documents' => $documents,
        'history_counts' => $history, 'mfa_decryption_fingerprint' => hash('sha256', implode('|', $mfa)),
        'mfa_credentials_decrypted' => count($mfa), 'constraints' => $constraints, 'triggers' => $triggers,
        'grants' => $grants, 'roles' => $roles, 'lineage_projects' => 1], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {
    fwrite(STDERR, json_encode(['event' => 'verification.restore_inventory_failed', 'phase' => $phase], JSON_THROW_ON_ERROR)."\n");
    exit(1);
}
