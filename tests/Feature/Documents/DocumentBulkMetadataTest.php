<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Infrastructure\Async\OperationRunner;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommercialDatabase;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;

final class DocumentBulkMetadataTest extends TestCase
{
    use CommercialDatabase;
    use DocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeDocuments();
    }

    public function test_bulk_metadata_requires_exact_parent_customer_and_user_for_every_identifier(): void
    {
        $owner = $this->documentOwner();
        $document = $this->finalized($owner);
        $foreign = $this->documentOwner();
        $other = $this->finalized($foreign);
        $service = app(DocumentService::class);
        $view = DB::transaction(fn () => $service->metadataMany($owner, [$document->id]));
        self::assertSame([$document->id], array_keys($view));
        self::assertSame($document->display_name, $view[$document->id]->filename);
        foreach ([
            [$owner, [$document->id, $other->id]],
            [$owner, [$document->id, (string) Str::uuid7()]],
            [$owner, [$document->id, 'invalid']],
            [new DocumentOwner($foreign->parentId, $owner->customerId, $owner->userId, $owner->actorId, $owner->requestId), [$document->id]],
            [new DocumentOwner($owner->parentId, $foreign->customerId, $owner->userId, $owner->actorId, $owner->requestId), [$document->id]],
            [new DocumentOwner($owner->parentId, $owner->customerId, $foreign->userId, $owner->actorId, $owner->requestId), [$document->id]],
        ] as [$scope, $ids]) {
            try {
                DB::transaction(fn () => $service->metadataMany($scope, $ids));
                self::fail('A partially authorized bulk metadata result was returned.');
            } catch (HttpException $error) {
                self::assertSame(404, $error->getStatusCode());
            }
        }
    }

    public function test_bulk_metadata_rejects_duplicate_or_excessive_identifiers_and_empty_is_query_free(): void
    {
        $owner = $this->documentOwner();
        $service = app(DocumentService::class);
        $id = (string) Str::uuid7();
        foreach ([[$id, $id], array_map(static fn (): string => (string) Str::uuid7(), range(1, 101))] as $ids) {
            try {
                DB::transaction(fn () => $service->metadataMany($owner, $ids));
                self::fail('An unbounded or duplicate metadata request was accepted.');
            } catch (ValidationException $error) {
                self::assertSame(422, $error->status);
            }
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        self::assertSame([], DB::transaction(fn () => $service->metadataMany($owner, [])));
        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_bulk_metadata_requires_the_authorized_transaction_even_for_empty_input(): void
    {
        $owner = $this->documentOwner();
        $this->expectException(LogicException::class);
        app(DocumentService::class)->metadataMany($owner, []);
    }

    public function test_mixed_scan_states_preserve_retryability_with_two_queries_for_the_entire_batch(): void
    {
        $owner = $this->documentOwner();
        $available = $this->finalized($owner);
        app(OperationRunner::class)->run($available->scan_operation_id);
        $pending = $this->finalized($owner);
        $failed = $this->finalized($owner);
        $exhausted = $this->finalized($owner);
        $this->scanner->unavailable = true;
        $service = app(DocumentService::class);
        foreach ([$failed, $exhausted] as $document) {
            for ($round = 0; $round < ($document->id === $exhausted->id ? 3 : 1); $round++) {
                if ($round > 0) {
                    DB::transaction(fn () => $service->retryScan($owner, $document->id));
                    $document->refresh();
                }
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $this->due($document->scan_operation_id);
                    app(OperationRunner::class)->run($document->scan_operation_id);
                }
            }
        }
        $ids = [$available->id, $pending->id, $failed->id, $exhausted->id];
        $individual = DB::transaction(function () use ($service, $owner, $ids): array {
            $views = [];
            foreach ($ids as $id) {
                $views[$id] = $service->metadata($owner, $id);
            }

            return $views;
        });
        DB::flushQueryLog();
        DB::enableQueryLog();
        $batch = DB::transaction(fn () => $service->metadataMany($owner, $ids));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertCount(2, $queries);
        self::assertEquals($individual, $batch);
        self::assertFalse($batch[$available->id]->retryable);
        self::assertFalse($batch[$pending->id]->retryable);
        self::assertTrue($batch[$failed->id]->retryable);
        self::assertFalse($batch[$exhausted->id]->retryable);
    }

    private function finalized(DocumentOwner $owner): Document
    {
        $reservation = $this->reservation($owner);
        $object = $this->uploadFixture($reservation);
        DB::transaction(fn () => app(DocumentService::class)->finalize($owner, $reservation->documentId, $object));

        return Document::query()->findOrFail($reservation->documentId);
    }
}
