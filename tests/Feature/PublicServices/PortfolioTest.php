<?php

declare(strict_types=1);

namespace Tests\Feature\PublicServices;

use App\Infrastructure\Async\OperationRunner;
use App\Modules\Identity\Authorization\Role;
use App\Modules\PublicPortfolio\Actions\ManagePortfolio;
use App\Modules\PublicPortfolio\Actions\ProcessPortfolioImage;
use App\Modules\PublicPortfolio\Actions\ReconcilePortfolio;
use App\Modules\PublicPortfolio\Contracts\ImageProcessor;
use App\Modules\PublicPortfolio\Contracts\PortfolioStorage;
use App\Modules\PublicPortfolio\Data\PortfolioActor;
use App\Modules\PublicPortfolio\Data\ProcessedImage;
use App\Modules\PublicPortfolio\Models\PortfolioAsset;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\Support\MemoryPortfolioStorage;
use Tests\Support\PublicContentHttp;
use Tests\TestCase;

final class PortfolioTest extends TestCase
{
    use CommercialDatabase, PublicContentHttp;

    private MemoryPortfolioStorage $storage;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->storage = new MemoryPortfolioStorage;
        app()->instance(PortfolioStorage::class, $this->storage);
        app()->instance(ImageProcessor::class, new class implements ImageProcessor
        {
            public function process(string $bytes, string $mediaType): ProcessedImage
            {
                return new ProcessedImage(null, [
                    'card' => ['bytes' => 'RIFFcardWEBPsynthetic', 'width' => 120, 'height' => 80],
                    'gallery' => ['bytes' => 'RIFFsizeWEBPsynthetic', 'width' => 120, 'height' => 80],
                ]);
            }
        });
        $this->initializeBrowser();
        $this->operatorId = $this->publicContentOperator(Role::Administrator)->id;
    }

    public function test_portfolio_editor_can_manage_images_and_publish_but_cannot_access_other_staff_domains(): void
    {
        $this->browser('POST', '/api/v1/auth/logout')->assertOk();
        $this->initializeBrowser();
        $this->operatorId = $this->publicContentOperator(Role::PortfolioEditor)->id;
        $caps = $this->browser('GET', '/api/v1/identity/me')->assertOk()->json('data.capabilities');
        self::assertContains('admin.dashboard.view', $caps);
        foreach (['reports.view', 'audit.investigate', 'admin.customers.view', 'categories.manage', 'projects.view', 'project_requests.view'] as $cap) {
            self::assertNotContains($cap, $caps);
        }
        foreach (['/api/v1/identity/staff', '/api/v1/admin/contact-messages', '/api/v1/admin/customers', '/api/v1/admin/reports/dashboard', '/api/v1/admin/audit-events'] as $url) {
            $this->browser('GET', $url)->assertForbidden();
        }
        self::assertSame([], $this->browser('GET', '/api/v1/identity/capabilities')->assertOk()->json('data.assignable_roles'));
        // Reuse the full immutable-publication workflow: create/edit, reserve/upload/process,
        // publish, re-publish, unpublish and inspect exactly what visitors can see.
        $this->publicationLifecycle();
    }

    public function test_publications_are_immutable_and_republication_never_revives_withdrawn_image_ids(): void
    {
        $this->publicationLifecycle();
    }

    private function publicationLifecycle(): void
    {
        $project = $this->project();
        $id = $project['id'];
        $asset = $this->image($id);
        $this->browser('GET', '/api/v1/public/portfolio/projects')->assertOk()->assertJsonCount(0, 'data');
        $this->browser('GET', '/api/v1/public/portfolio/projects/'.$id)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->browser('GET', '/api/v1/public/portfolio/images/'.$asset.'/card')->assertNotFound();
        $publication = $this->publish($id);
        $public = $this->browser('GET', '/api/v1/public/portfolio/projects/'.$id)->assertOk();
        self::assertSame([], $public->headers->getCookies());
        self::assertTrue($public->headers->hasCacheControlDirective('public'));
        self::assertSame('60', $public->headers->getCacheControlDirective('s-maxage'));
        self::assertSame('0', $public->headers->getCacheControlDirective('max-age'));
        self::assertFalse($public->headers->hasCacheControlDirective('no-store'));
        $publicId = $public->json('data.images.0.id');
        self::assertNotSame($asset, $publicId);
        $this->browser('GET', '/api/v1/public/portfolio/images/'.$publicId.'/card')->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->browser('GET', '/api/v1/public/portfolio/categories')->assertOk()->assertJsonPath('data.0.published_count', 1);
        $draft = $this->browser('PATCH', '/api/v1/admin/portfolio/projects/'.$id, ['title' => 'An edited draft'], ['If-Match' => $publication['etag']])->assertOk()->json('data');
        $this->browser('GET', '/api/v1/public/portfolio/projects/'.$id)->assertOk()->assertJsonPath('data.title', 'Synthetic Portfolio');
        $this->browser('DELETE', '/api/v1/admin/portfolio/projects/'.$id.'/images/'.$asset, [], ['If-Match' => $draft['etag']])->assertConflict();
        $second = $this->publish($id);
        $newPublic = $this->browser('GET', '/api/v1/public/portfolio/projects/'.$id)->assertOk()->assertJsonPath('data.title', 'An edited draft')->json('data.images.0.id');
        self::assertNotSame($newPublic, $publicId);
        $this->browser('GET', '/api/v1/public/portfolio/images/'.$publicId.'/card')->assertNotFound();
        $this->browser('POST', '/api/v1/admin/portfolio/projects/'.$id.'/unpublications', [], ['If-Match' => $second['etag'], 'Idempotency-Key' => (string) Str::uuid7()])->assertOk();
        foreach ([$publicId, $newPublic] as $image) {
            foreach (['card', 'gallery'] as $variant) {
                $this->browser('GET', '/api/v1/public/portfolio/images/'.$image.'/'.$variant)->assertNotFound();
            }
        }
        $this->browser('GET', '/api/v1/public/portfolio/projects/'.$id)->assertNotFound();
        $this->browser('GET', '/api/v1/public/portfolio/categories')->assertOk()->assertJsonPath('data.0.published_count', 0);
        $this->publish($id);
        $this->browser('GET', '/api/v1/public/portfolio/images/'.$newPublic.'/gallery')->assertNotFound();
        $this->assertDatabaseCount('portfolio_publications', 3);
        $this->assertDatabaseCount('portfolio_invalidations', 4);
    }

    public function test_exact_publication_retry_is_stable_and_conflict_does_not_publish_twice(): void
    {
        $id = $this->project()['id'];
        $this->image($id);
        $etag = $this->selectImages($id);
        $key = (string) Str::uuid7();
        $url = '/api/v1/admin/portfolio/projects/'.$id.'/publications';
        $one = $this->browser('POST', $url, ['rights_confirmed' => true], ['If-Match' => $etag, 'Idempotency-Key' => $key])->assertCreated();
        $two = $this->browser('POST', $url, ['rights_confirmed' => true], ['If-Match' => $etag, 'Idempotency-Key' => $key])->assertCreated();
        self::assertSame($one->json(), $two->json());
        $this->browser('POST', $url, ['rights_confirmed' => true], ['If-Match' => $two->json('data.etag'), 'Idempotency-Key' => $key])->assertConflict();
        $this->assertDatabaseCount('portfolio_publications', 1);
        $this->assertDatabaseCount('portfolio_command_keys', 1);
        self::assertSame([], $one->headers->getCookies());
    }

    public function test_unpublication_during_slow_object_read_wins_before_delivery(): void
    {
        $id = $this->project()['id'];
        $this->image($id);
        $published = $this->publish($id);
        $publicId = $this->browser('GET', '/api/v1/public/portfolio/projects/'.$id)->json('data.images.0.id');
        $this->storage->afterRead = function () use ($id, $published): void {
            $this->storage->afterRead = null;
            DB::transaction(fn () => app(ManagePortfolio::class)->publication(new PortfolioActor($this->operatorId, ['portfolio.publish'], true), $id, false, $published['etag'], (string) Str::uuid7(), (string) Str::uuid7()));
        };
        $this->browser('GET', '/api/v1/public/portfolio/images/'.$publicId.'/card')->assertNotFound();
    }

    public function test_upload_rechecks_revoked_authority_after_storage_and_emits_no_cookie(): void
    {
        $id = $this->project()['id'];
        $reserved = $this->reserve($id);
        $this->storage->afterWrite = function (): void {
            DB::table('users')->where('id', $this->operatorId)->update(['enabled' => false]);
        };
        $reply = $this->rawUpload($id, $reserved['id'], $reserved['etag'])->assertUnauthorized();
        self::assertSame([], $reply->headers->getCookies());
        $this->assertDatabaseHas('portfolio_assets', ['id' => $reserved['id'], 'state' => 'reserved', 'source_version' => null, 'operation_id' => null]);
    }

    public function test_worker_rejects_revoked_manager_and_cannot_restore_removed_image(): void
    {
        $id = $this->project()['id'];
        $reserved = $this->reserve($id);
        $this->rawUpload($id, $reserved['id'], $reserved['etag'])->assertAccepted();
        DB::table('users')->where('id', $this->operatorId)->update(['enabled' => false]);
        app(OperationRunner::class)->run(PortfolioAsset::query()->findOrFail($reserved['id'])->operation_id);
        $this->assertDatabaseHas('portfolio_assets', ['id' => $reserved['id'], 'state' => 'rejected', 'failure_code' => 'authority_revoked']);
        $this->assertDatabaseHas('portfolio_projects', ['id' => $id, 'publication_id' => null]);
    }

    public function test_cross_project_image_and_missing_confirmation_are_rejected(): void
    {
        $one = $this->project()['id'];
        $image = $this->image($one);
        $two = $this->project();
        $this->browser('PATCH', '/api/v1/admin/portfolio/projects/'.$two['id'], ['cover_image_id' => $image], ['If-Match' => $two['etag']])->assertUnprocessable();
        $this->browser('GET', '/api/v1/admin/portfolio/projects/'.$two['id'].'/images/'.$image)->assertNotFound();
        $this->browser('POST', '/api/v1/admin/portfolio/projects/'.$one.'/publications', ['rights_confirmed' => false])->assertUnprocessable();
        $etag = $this->selectImages($one);
        $this->travel(6)->minutes();
        $this->browser('POST', '/api/v1/admin/portfolio/projects/'.$one.'/publications', ['rights_confirmed' => true], ['If-Match' => $etag, 'Idempotency-Key' => (string) Str::uuid7()])->assertForbidden();
        $this->travelBack();
        $this->assertDatabaseCount('portfolio_publications', 0);
    }

    public function test_public_reads_are_identical_with_stale_active_or_absent_sessions(): void
    {
        $id = $this->project()['id'];
        $this->image($id);
        $this->publish($id);
        $url = '/api/v1/public/portfolio/projects';
        $active = $this->browser('GET', $url)->assertOk();
        $cookies = $this->browserCookies;
        $this->browser('POST', '/api/v1/auth/logout')->assertOk();
        $this->browserCookies = $cookies;
        $stale = $this->browser('GET', $url)->assertOk();
        $this->browserCookies = [];
        $anonymous = $this->browser('GET', $url)->assertOk();
        self::assertSame($active->json(), $stale->json());
        self::assertSame($active->json(), $anonymous->json());
        foreach ([$active, $stale, $anonymous] as $response) {
            self::assertSame([], $response->headers->getCookies());
        }
    }

    public function test_public_cursor_is_scoped_to_filter_and_excludes_drafts(): void
    {
        foreach (range(1, 3) as $i) {
            $id = $this->project()['id'];
            $this->image($id);
            $this->publish($id);
        }
        $this->project();
        $first = $this->browser('GET', '/api/v1/public/portfolio/projects?limit=2&category=web')->assertOk()->assertJsonCount(2, 'data');
        $cursor = $first->json('meta.next_cursor');
        self::assertNotNull($cursor);
        $second = $this->browser('GET', '/api/v1/public/portfolio/projects?limit=2&category=web&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data');
        self::assertNotContains($second->json('data.0.id'), array_column($first->json('data'), 'id'));
        $this->browser('GET', '/api/v1/public/portfolio/projects?category=mobile&cursor='.urlencode($cursor))->assertUnprocessable();
        $this->browser('GET', '/api/v1/public/portfolio/projects?limit=49')->assertUnprocessable();
        $this->browser('GET', '/api/v1/public/portfolio/projects?url=https://example.test')->assertUnprocessable();
    }

    public function test_admin_lists_and_retirement_are_versioned_and_audited(): void
    {
        $id = $this->project()['id'];
        $asset = $this->reserve($id);
        $this->browser('GET', '/api/v1/admin/portfolio/projects?status=draft&limit=1')->assertOk()->assertJsonCount(1, 'data');
        $project = $this->browser('GET', '/api/v1/admin/portfolio/projects/'.$id)->assertOk()->json('data');
        $this->browser('DELETE', '/api/v1/admin/portfolio/projects/'.$id.'/images/'.$asset['id'], [], ['If-Match' => $project['etag']])->assertOk()->assertJsonCount(0, 'data.images');
        $this->browser('GET', '/api/v1/admin/portfolio/projects/'.$id.'/images/'.$asset['id'])->assertOk()->assertJsonPath('data.state', 'removed');
        $this->assertDatabaseHas('audit_events', ['event_type' => 'portfolio.image_removed', 'subject_id' => $asset['id']]);
    }

    #[DataProvider('publicationRaces')]
    public function test_real_postgresql_publication_race_is_serialized(bool $sameKey): void
    {
        $id = $this->project()['id'];
        $this->image($id);
        $etag = $this->selectImages($id);
        $key = (string) Str::uuid7();
        $name = 'portfolio-race-'.Str::uuid7();
        $workers = [];
        DB::beginTransaction();
        DB::table('portfolio_projects')->where('id', $id)->lockForUpdate()->firstOrFail();
        try {
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/portfolio-concurrency-worker.php')], base_path(), timeout: 20);
                $worker->setInput(json_encode(['id' => $id, 'actor' => $this->operatorId, 'etag' => $etag, 'key' => $sameKey ? $key : (string) Str::uuid7(), 'name' => $name.'-'.$i], JSON_THROW_ON_ERROR));
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'like', $name.'%')->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                } usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting);
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            self::assertSame($sameKey ? [201, 201] : [201, 412], $statuses);
            self::assertNotSame($results[0]['pid'], $results[1]['pid']);
            if ($sameKey) {
                self::assertSame($results[0]['result'], $results[1]['result']);
            }
            $this->assertDatabaseCount('portfolio_publications', 1);
            $this->assertDatabaseCount('portfolio_invalidations', 1);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0);
                }
            }
        }
    }

    public static function publicationRaces(): array
    {
        return [[true], [false]];
    }

    public function test_expiry_and_late_completion_cannot_revive_or_purge_live_images(): void
    {
        $id = $this->project()['id'];
        $asset = $this->reserve($id);
        DB::table('portfolio_assets')->where('id', $asset['id'])->update(['expires_at' => now()->subMinute(), 'lock_version' => 2]);
        $reconcile = app(ReconcilePortfolio::class);
        self::assertSame(1, $reconcile->handle(20)['expired']);
        $this->assertDatabaseHas('portfolio_assets', ['id' => $asset['id'], 'state' => 'expired']);
        $next = $this->reserve($id);
        $this->rawUpload($id, $next['id'], $next['etag'])->assertAccepted();
        $operation = PortfolioAsset::query()->findOrFail($next['id'])->operation_id;
        $runner = app(OperationRunner::class);
        $claim = $runner->claim($operation);
        self::assertNotNull($claim);
        $writer = app(ProcessPortfolioImage::class)->execute($claim);
        $project = $this->browser('GET', '/api/v1/admin/portfolio/projects/'.$id)->json('data');
        $this->browser('DELETE', '/api/v1/admin/portfolio/projects/'.$id.'/images/'.$next['id'], [], ['If-Match' => $project['etag']])->assertOk();
        $runner->complete($claim, $writer);
        $this->assertDatabaseHas('portfolio_assets', ['id' => $next['id'], 'state' => 'removed']);
        self::assertSame(0, $reconcile->handle(20)['purged']);
        DB::table('portfolio_assets')->where('id', $next['id'])->update(['retired_at' => now()->subDays(2), 'lock_version' => DB::raw('lock_version+1')]);
        self::assertSame(1, $reconcile->handle(20)['purged']);
        $ready = $this->image($id);
        $this->publish($id);
        self::assertSame(0, $reconcile->handle(20)['purged']);
        $this->assertDatabaseHas('portfolio_assets', ['id' => $ready, 'state' => 'ready', 'purged_at' => null]);
    }

    public function test_failed_processor_is_reconciled_and_database_history_cannot_be_rewritten(): void
    {
        $id = $this->project()['id'];
        $asset = $this->reserve($id);
        $this->rawUpload($id, $asset['id'], $asset['etag'])->assertAccepted();
        $operation = PortfolioAsset::query()->findOrFail($asset['id'])->operation_id;
        DB::table('async_operations')->where('id', $operation)->update(['state' => 'failed', 'failure_code' => 'execution_failed', 'completed_at' => now()]);
        app(ReconcilePortfolio::class)->handle(20);
        $this->assertDatabaseHas('portfolio_assets', ['id' => $asset['id'], 'state' => 'rejected', 'failure_code' => 'processing_failed']);
        foreach ([fn () => DB::table('portfolio_projects')->where('id', $id)->update(['public_id' => (string) Str::uuid(), 'lock_version' => DB::raw('lock_version+1')]),
            fn () => DB::table('portfolio_assets')->where('id', $asset['id'])->update(['state' => 'reserved', 'lock_version' => DB::raw('lock_version+1')]),
            fn () => (require database_path('migrations/2026_09_29_000200_create_public_portfolio.php'))->down()] as $write) {
            try {
                DB::transaction($write);
                self::fail('Historical mutation should fail.');
            } catch (QueryException $error) {
                self::assertNotEmpty($error->getCode());
            }
        }
        $this->assertDatabaseHas('portfolio_projects', ['id' => $id]);
    }

    private function project(): array
    {
        return $this->browser('POST', '/api/v1/admin/portfolio/projects', ['title' => 'Synthetic Portfolio', 'summary' => 'A synthetic portfolio for acceptance testing.',
            'description' => 'A long synthetic description for isolated portfolio acceptance testing.', 'category_id' => 'web'])->assertCreated()->json('data');
    }

    private function reserve(string $id): array
    {
        $etag = $this->browser('GET', '/api/v1/admin/portfolio/projects/'.$id)->assertOk()->json('data.etag');

        return $this->browser('POST', '/api/v1/admin/portfolio/projects/'.$id.'/images', ['media_type' => 'image/png', 'byte_size' => strlen('synthetic source'),
            'sha256' => hash('sha256', 'synthetic source'), 'alt' => 'Synthetic image', 'display_order' => 0], ['If-Match' => $etag])->assertCreated()->json('data');
    }

    private function image(string $id): string
    {
        $asset = $this->reserve($id);
        $this->rawUpload($id, $asset['id'], $asset['etag'])->assertAccepted();
        app(OperationRunner::class)->run(PortfolioAsset::query()->findOrFail($asset['id'])->operation_id);
        $this->browser('GET', '/api/v1/admin/portfolio/projects/'.$id.'/images/'.$asset['id'])->assertOk()->assertJsonPath('data.state', 'ready');

        return $asset['id'];
    }

    private function selectImages(string $id): string
    {
        $project = $this->browser('GET', '/api/v1/admin/portfolio/projects/'.$id)->assertOk()->json('data');

        return $this->browser('PATCH', '/api/v1/admin/portfolio/projects/'.$id, ['cover_image_id' => $project['images'][0]['id'], 'featured_image_id' => $project['images'][0]['id']],
            ['If-Match' => $project['etag']])->assertOk()->json('data.etag');
    }

    private function publish(string $id): array
    {
        return $this->browser('POST', '/api/v1/admin/portfolio/projects/'.$id.'/publications', ['rights_confirmed' => true],
            ['If-Match' => $this->selectImages($id), 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated()->json('data');
    }

    private function rawUpload(string $project, string $image, string $etag): TestResponse
    {
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');

        return $this->call('PUT', 'https://localhost:8443/api/v1/admin/portfolio/projects/'.$project.'/images/'.$image.'/content', [], $this->browserCookies, [],
            ['HTTPS' => 'on', 'HTTP_HOST' => 'localhost:8443', 'SERVER_PORT' => '8443', 'REMOTE_ADDR' => $this->browserIp, 'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ORIGIN' => 'https://localhost:8443', 'HTTP_X_XSRF_TOKEN' => $this->browserCookies['XSRF-TOKEN'], 'HTTP_IF_MATCH' => $etag], 'synthetic source');
    }
}
