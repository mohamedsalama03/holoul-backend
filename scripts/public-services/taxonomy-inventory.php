<?php

// Read-only inventory. It cannot disable, rename, move, or delete taxonomy.
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$result = DB::transaction(function (): array {
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
    $categories = DB::select("SELECT c.id,c.name,c.slug,c.active,c.display_order,c.lock_version,
        (SELECT count(*) FROM subcategories s WHERE s.category_id=c.id) subcategory_count,
        (SELECT count(*) FROM request_drafts d WHERE d.category_id=c.id) draft_rows,
        (SELECT count(*) FROM request_drafts d WHERE d.category_id=c.id AND d.is_open) open_draft_rows,
        (SELECT count(*) FROM request_revisions r WHERE r.category_id=c.id) revision_rows
        FROM categories c WHERE c.slug LIKE 'b8-load-%' ORDER BY c.id");
    $subcategories = DB::select("SELECT s.id,s.category_id,s.name,s.slug,s.active,s.display_order,s.lock_version,
        (SELECT count(*) FROM request_drafts d WHERE d.subcategory_id=s.id) draft_rows,
        (SELECT count(*) FROM request_drafts d WHERE d.subcategory_id=s.id AND d.is_open) open_draft_rows,
        (SELECT count(*) FROM request_revisions r WHERE r.subcategory_id=s.id) revision_rows
        FROM subcategories s JOIN categories c ON c.id=s.category_id WHERE c.slug LIKE 'b8-load-%' ORDER BY s.id");

    return ['context' => DB::selectOne('SELECT current_database() database,current_user identity,clock_timestamp() captured_at'),
        'read_only' => DB::scalar('SHOW transaction_read_only'), 'selector' => "categories.slug LIKE 'b8-load-%'", 'categories' => $categories, 'subcategories' => $subcategories,
        'counts' => ['categories' => DB::table('categories')->count(), 'subcategories' => DB::table('subcategories')->count(),
            'requests' => DB::table('project_requests')->count(), 'drafts' => DB::table('request_drafts')->count(), 'revisions' => DB::table('request_revisions')->count()],
        'action' => 'REVIEW ONLY; no deactivation authorized by this inventory'];
});
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
