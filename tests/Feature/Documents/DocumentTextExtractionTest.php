<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Infrastructure\Async\OperationRunner;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Contracts\DocumentTextExtractor;
use App\Modules\Documents\Contracts\DocumentTextService;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Data\DocumentTextSource;
use App\Modules\Documents\Data\MalwareVerdict;
use App\Modules\Documents\Exceptions\DocumentExtractionRejected;
use App\Modules\Documents\Exceptions\DocumentSourceUnavailable;
use App\Modules\Documents\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommercialDatabase;
use Tests\Support\DocumentFixtures;
use Tests\Support\DocumentTextExtractorDouble;
use Tests\TestCase;

final class DocumentTextExtractionTest extends TestCase
{
    use CommercialDatabase;
    use DocumentFixtures;

    private DocumentTextExtractorDouble $textExtractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeDocuments();
        $this->textExtractor = new DocumentTextExtractorDouble;
        app()->instance(DocumentTextExtractor::class, $this->textExtractor);
    }

    public function test_available_source_captures_exact_private_version_and_extracts_only_after_verified_storage(): void
    {
        [$owner, $document, $source] = $this->source();
        $opened = false;
        $this->objects->afterOpen = function () use (&$opened): void {
            self::assertSame(0, DB::transactionLevel());
            $opened = true;
        };
        self::assertSame($document->expected_sha256, $source->sha256);
        self::assertSame($document->storage_version, $source->storageVersion);
        self::assertSame($document->lock_version, $source->documentVersion);
        self::assertEquals($source, DocumentTextSource::fromArray($source->toArray()));
        self::assertSame(['document_id', 'parent_id', 'customer_id', 'customer_user_id', 'document_version', 'storage_version', 'sha256', 'format', 'bytes'], array_keys($source->toArray()));
        DB::transaction(fn () => app(DocumentTextService::class)->assertCurrent($owner, $source));
        self::assertSame($this->textExtractor->text, app(DocumentTextService::class)->extract($source));
        self::assertTrue($opened);
        self::assertSame(1, $this->textExtractor->calls);
    }

    public function test_uploading_quarantined_and_rejected_documents_cannot_be_captured_or_extracted(): void
    {
        $owner = $this->documentOwner();
        $reservation = $this->reservation($owner);
        $this->captureDenied($owner, $reservation->documentId);
        $object = $this->uploadFixture($reservation);
        DB::transaction(fn () => app(DocumentService::class)->finalize($owner, $reservation->documentId, $object));
        $this->captureDenied($owner, $reservation->documentId);
        $this->scanner->verdict = MalwareVerdict::Infected;
        app(OperationRunner::class)->run(Document::query()->findOrFail($reservation->documentId)->scan_operation_id);
        $this->assertDatabaseHas('documents', ['id' => $reservation->documentId, 'state' => 'rejected']);
        $this->captureDenied($owner, $reservation->documentId);
        self::assertSame(0, $this->textExtractor->calls);
    }

    #[DataProvider('alteredSources')]
    public function test_changed_source_identity_is_rejected_before_parser_or_storage_access(string $field, mixed $value): void
    {
        [, , $source] = $this->source();
        $this->objects->afterOpen = static function (): void {
            self::fail('An altered source reached storage.');
        };
        $altered = DocumentTextSource::fromArray([...$source->toArray(), $field => $value]);
        try {
            app(DocumentTextService::class)->extract($altered);
            self::fail('An altered document source was accepted.');
        } catch (DocumentSourceUnavailable) {
            self::assertSame(0, $this->textExtractor->calls);
        }
    }

    public static function alteredSources(): array
    {
        return [['sha256', str_repeat('0', 64)], ['storage_version', 'another-immutable-version'], ['document_version', 999],
            ['format', 'docx'], ['bytes', 1], ['parent_id', '01990000-0000-7000-8000-000000000001'],
            ['customer_id', '01990000-0000-7000-8000-000000000002']];
    }

    public function test_capture_rejects_foreign_customer_and_parent_context(): void
    {
        [$owner, $document] = $this->source();
        foreach ([new DocumentOwner((string) Str::uuid7(), $owner->customerId, $owner->userId, $owner->actorId, $owner->requestId),
            new DocumentOwner($owner->parentId, (string) Str::uuid7(), $owner->userId, $owner->actorId, $owner->requestId),
            new DocumentOwner($owner->parentId, $owner->customerId, (string) Str::uuid7(), $owner->actorId, $owner->requestId)] as $foreign) {
            $this->captureDenied($foreign, $document->id);
        }
    }

    public function test_source_is_rechecked_after_offline_extraction_before_any_text_is_returned(): void
    {
        [$owner, $document, $source] = $this->source();
        $this->textExtractor->afterExtract = function () use ($owner, $document): void {
            self::assertSame(0, DB::transactionLevel());
            DB::transaction(fn () => app(DocumentService::class)->requestDeletion($owner, $document->id));
        };
        $this->expectException(DocumentSourceUnavailable::class);
        app(DocumentTextService::class)->extract($source);
    }

    #[DataProvider('invalidText')]
    public function test_parser_output_is_bounded_and_validated_before_leaving_documents(string $text, int $maximum, string $reason): void
    {
        [, , $source] = $this->source();
        $this->textExtractor->text = $text;
        try {
            app(DocumentTextService::class)->extract($source, $maximum);
            self::fail('Unsafe parser output was accepted.');
        } catch (DocumentExtractionRejected $failure) {
            self::assertSame($reason, $failure->reasonCode);
            self::assertStringNotContainsString($text === '' ? 'no_text' : $text, $failure->getMessage());
        }
    }

    public static function invalidText(): array
    {
        return [['', 100, 'no_text'], ['Oversized document text.', 5, 'resource_limit'], ["bad\0text", 100, 'invalid_structure'], ["bad\xFFtext", 100, 'invalid_structure']];
    }

    public function test_expensive_extraction_cannot_run_inside_business_transaction(): void
    {
        [, , $source] = $this->source();
        $this->expectException(LogicException::class);
        DB::transaction(fn () => app(DocumentTextService::class)->extract($source));
    }

    private function captureDenied(DocumentOwner $owner, string $id): void
    {
        try {
            DB::transaction(fn () => app(DocumentTextService::class)->capture($owner, $id));
            self::fail('An unavailable or foreign document source was captured.');
        } catch (DocumentSourceUnavailable) {
            self::assertSame(0, $this->textExtractor->calls);
        }
    }

    /** @return array{DocumentOwner,Document,DocumentTextSource} */
    private function source(): array
    {
        [$owner, $document] = $this->quarantined();
        app(OperationRunner::class)->run($document->scan_operation_id);
        $document->refresh();
        $source = DB::transaction(fn () => app(DocumentTextService::class)->capture($owner, $document->id));

        return [$owner, $document, $source];
    }
}
