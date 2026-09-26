<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Infrastructure\Async\OperationRunner;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Documents\Models\Document;
use App\Modules\Projects\Actions\ProjectDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\CommercialDatabase;
use Tests\Support\DocumentFixtures;
use Tests\Support\IdentityHttp;
use Tests\Support\ProjectFixtures;
use Tests\TestCase;

final class ProjectDocumentListingQueryTest extends TestCase
{
    use CommercialDatabase;
    use DocumentFixtures;
    use IdentityHttp;
    use ProjectFixtures;

    public function test_document_list_query_count_is_constant_for_one_three_and_twenty_five_documents(): void
    {
        $this->initializeDocuments();
        $fixture = $this->projectFixture();
        $project = $fixture['project'];
        $actor = $this->projectActor($fixture['author']);
        $attachments = app(ProjectDocuments::class);
        $this->initializeBrowser();
        $this->signIn($fixture['customer'])->assertOk();
        $measurements = [];
        for ($number = 1; $number <= 25; $number++) {
            $reservation = DB::transaction(fn () => $attachments->reserve($project, $actor,
                VersionPrecondition::etag($project->id, $project->lock_version), 'bounded-'.$number.'.pdf', strlen(self::DOCUMENT_PDF),
                hash('sha256', self::DOCUMENT_PDF), 'customer', (string) Str::uuid7(), (string) Str::uuid7()));
            $object = $this->uploadFixture($reservation);
            DB::transaction(fn () => $attachments->finalize($project, $actor, $reservation->documentId, $object,
                VersionPrecondition::etag($project->id, $project->lock_version), (string) Str::uuid7()));
            app(OperationRunner::class)->run(Document::query()->findOrFail($reservation->documentId)->scan_operation_id);
            if (! in_array($number, [1, 3, 25], true)) {
                continue;
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $view = $this->browser('GET', '/api/v1/projects/'.$project->id.'/documents')->assertOk()->assertJsonCount($number, 'data');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $selects = array_filter($queries, static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'));
            $measurements[] = ['documents' => $number, 'select_queries' => count($selects), 'total_queries' => count($queries)];
            self::assertSame($number, $view->json('meta.total'));
            self::assertStringNotContainsString('storage_version', $view->getContent());
        }
        File::ensureDirectoryExists(base_path('artifacts'));
        file_put_contents(base_path('artifacts/b8-document-list-optimized.json'), json_encode($measurements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        self::assertSame($measurements[0]['select_queries'], $measurements[1]['select_queries']);
        self::assertSame($measurements[0]['select_queries'], $measurements[2]['select_queries']);
        $this->browser('GET', '/api/v1/projects/'.$project->id.'/documents?per_page=100')->assertOk()->assertJsonCount(25, 'data');
        $this->browser('GET', '/api/v1/projects/'.$project->id.'/documents?per_page=101')->assertUnprocessable();
        $this->browser('GET', '/api/v1/projects/'.$project->id.'/documents?page=2')->assertOk()->assertJsonCount(0, 'data');
    }
}
