<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Infrastructure\Async\OperationRunner;
use App\Modules\Documents\Contracts\DocumentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;

final class DocumentConstraintsTest extends TestCase
{
    use DatabaseMigrations;
    use DocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeDocuments();
    }

    #[DataProvider('immutableColumns')]
    public function test_database_rejects_document_identity_and_finalized_object_mutation(string $column, mixed $value): void
    {
        [, $document] = $this->quarantined();
        try {
            DB::transaction(fn () => DB::table('documents')->where('id', $document->id)->update([$column => $value]));
            self::fail('Immutable document identity changed.');
        } catch (QueryException $exception) {
            self::assertSame('23514', $exception->getCode());
        }
    }

    public static function immutableColumns(): iterable
    {
        foreach (['customer_id', 'customer_user_id', 'parent_id', 'uploader_id'] as $column) {
            yield $column => [$column, '01995000-0000-7000-8000-000000000001'];
        }
        yield 'checksum' => ['expected_sha256', str_repeat('a', 64)];
        yield 'size' => ['expected_size', 10485761];
        yield 'key' => ['storage_key', 'public/customer.pdf'];
        yield 'version' => ['storage_version', 'another-version'];
        yield 'version-cleared' => ['storage_version', null];
        yield 'display-name' => ['display_name', "changed\r\n.pdf"];
        yield 'format' => ['format', 'docx'];
        yield 'uploaded-at' => ['uploaded_at', null];
        yield 'generation' => ['scan_generation', -1];
    }

    public function test_document_tombstones_cannot_be_deleted_truncated_or_resurrected(): void
    {
        [$owner, $document] = $this->quarantined();
        DB::transaction(fn () => app(DocumentService::class)->requestDeletion($owner, $document->id));
        $document->refresh();
        app(OperationRunner::class)->run($document->delete_operation_id);
        foreach (['DELETE FROM documents WHERE id = ?', 'TRUNCATE documents'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql, str_contains($sql, '?') ? [$document->id] : []));
                self::fail('Tombstone history was removed.');
            } catch (QueryException $exception) {
                self::assertContains($exception->getCode(), ['55000', '0A000']);
            }
        }
        try {
            DB::transaction(fn () => DB::table('documents')->where('id', $document->id)->update(['state' => 'uploading', 'deleted_at' => null]));
            self::fail('A deleted document was resurrected.');
        } catch (QueryException $exception) {
            self::assertSame('23514', $exception->getCode());
        }
        self::assertFalse(DB::scalar("SELECT has_table_privilege('holoul_app','documents','DELETE')"));
        self::assertFalse(DB::scalar("SELECT has_table_privilege('holoul_app','documents','TRUNCATE')"));
    }

    public function test_typed_attachment_prevents_direct_sql_deletion(): void
    {
        [$owner, $document] = $this->quarantined();
        DB::table('intake_draft_documents')->insert(['draft_id' => DB::table('request_drafts')->where('request_id', $owner->parentId)->value('id'),
            'request_id' => $owner->parentId, 'customer_id' => $owner->customerId, 'document_id' => $document->id]);
        try {
            DB::transaction(fn () => DB::table('documents')->where('id', $document->id)->update(['state' => 'deleting']));
            self::fail('SQL deleted a referenced document.');
        } catch (QueryException $exception) {
            self::assertSame('23514', $exception->getCode());
        }
        self::assertArrayHasKey($document->storage_key, $this->objects->objects);
    }
}
