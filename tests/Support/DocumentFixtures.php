<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Documents\Actions\StoreUpload;
use App\Modules\Documents\Contracts\DocumentInspector;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Contracts\MalwareScanner;
use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Data\StoredObject;
use App\Modules\Documents\Data\UploadReservation;
use App\Modules\Documents\Models\Document;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

trait DocumentFixtures
{
    use IntakeFixtures;

    private const string DOCUMENT_PDF = "%PDF-1.4\n1 0 obj\n<</Type /Catalog>>\nendobj\n%%EOF\n";

    private DocumentMemoryStore $objects;

    private DocumentScannerDouble $scanner;

    private DocumentInspectorDouble $inspector;

    private function initializeDocuments(): void
    {
        Queue::fake();
        $this->objects = new DocumentMemoryStore;
        $this->scanner = new DocumentScannerDouble;
        $this->inspector = new DocumentInspectorDouble;
        app()->instance(PrivateObjectStore::class, $this->objects);
        app()->instance(MalwareScanner::class, $this->scanner);
        app()->instance(DocumentInspector::class, $this->inspector);
    }

    private function documentOwner(): DocumentOwner
    {
        $user = $this->intakeCustomer();
        $request = app(ManageDraft::class)->create($this->intakeActor($user), [], (string) Str::uuid7());

        return new DocumentOwner($request->id, $request->customer_id, $user->id, $user->id, (string) Str::uuid7());
    }

    private function reservation(DocumentOwner $owner, ?string $key = null, string $filename = 'brief.pdf'): UploadReservation
    {
        return DB::transaction(fn () => app(DocumentService::class)->reserve($owner, $filename,
            strlen(self::DOCUMENT_PDF), hash('sha256', self::DOCUMENT_PDF), $key ?? (string) Str::uuid7()));
    }

    private function uploadFixture(UploadReservation $reservation): StoredObject
    {
        $stream = tmpfile();
        self::assertIsResource($stream);
        fwrite($stream, self::DOCUMENT_PDF);
        rewind($stream);
        try {
            return app(StoreUpload::class)->handle($reservation, $stream);
        } finally {
            fclose($stream);
        }
    }

    /** @return array{DocumentOwner,Document} */
    private function quarantined(): array
    {
        $owner = $this->documentOwner();
        $reservation = $this->reservation($owner);
        $object = $this->uploadFixture($reservation);
        DB::transaction(fn () => app(DocumentService::class)->finalize($owner, $reservation->documentId, $object));

        return [$owner, Document::query()->findOrFail($reservation->documentId)];
    }

    private function due(string $operation): void
    {
        DB::table('async_operations')->where('id', $operation)->update(['next_attempt_at' => now()->subSecond()]);
    }
}
