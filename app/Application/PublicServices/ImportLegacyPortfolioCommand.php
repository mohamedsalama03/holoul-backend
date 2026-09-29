<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Contracts\IdentityReader;
use App\Modules\PublicPortfolio\Actions\ImportPortfolio;
use App\Modules\PublicPortfolio\Actions\UploadPortfolioImage;
use App\Modules\PublicPortfolio\Contracts\PortfolioStorage;
use App\Modules\PublicPortfolio\Data\PortfolioActor;
use App\Modules\PublicPortfolio\Models\PortfolioAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ImportLegacyPortfolioCommand extends Command
{
    protected $signature = 'portfolio:import-legacy {manifest} {--apply} {--actor=}';

    protected $description = 'Verify a frozen legacy bundle; local apply creates resumable drafts only.';

    public function handle(LegacyPortfolioReader $reader, ImportPortfolio $import, UploadPortfolioImage $upload, PortfolioStorage $storage): int
    {
        $path = $this->argument('manifest');
        $projects = $reader->read($path); // Verify the complete bundle before the first database write.
        $apply = $this->option('apply') === true;
        if ($apply && ! app()->environment(['local', 'testing'])) {
            $this->error('Apply requires the reviewed local/testing cutover context.');

            return self::FAILURE;
        }
        $result = [];
        foreach ($projects as $source) {
            $row = ['source_id' => $source->id, 'source_status' => $source->sourceStatus, 'manifest_hash' => $source->hash, 'images' => count($source->images), 'target_status' => 'draft'];
            if ($apply) {
                $requestId = (string) Str::uuid7();
                $mapping = DB::transaction(fn (): array => $import->reserve($this->actor(), $source, $requestId));
                foreach ($source->images as $image) {
                    $assetId = $mapping['images'][$image->id];
                    $asset = DB::transaction(function () use ($assetId, $mapping, $upload): ?PortfolioAsset {
                        $actor = $this->actor();
                        $asset = PortfolioAsset::query()->whereKey($assetId)->firstOrFail();
                        if (in_array($asset->state, ['processing', 'ready'], true)) {
                            return null;
                        }

                        return $upload->authorize($actor, $mapping['project_id'], $assetId, '"'.$assetId.':'.$asset->lock_version.'"');
                    });
                    if ($asset === null) {
                        continue;
                    }
                    $bytes = file_get_contents($image->path);
                    if (! is_string($bytes) || strlen($bytes) !== $image->size || ! hash_equals($image->sha256, hash('sha256', $bytes))) {
                        throw new RuntimeException('Frozen image changed; abort and review.');
                    }
                    $version = $storage->put($assetId, 'source', $bytes);
                    DB::transaction(fn () => $upload->finish($this->actor(), $mapping['project_id'], $assetId, '"'.$assetId.':'.$asset->lock_version.'"', $version, $requestId));
                }
                $row['project_id'] = $mapping['project_id'];
            }
            $result[] = $row;
        }
        $this->line(json_encode(['applied' => $apply, 'projects' => $result], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function actor(): PortfolioActor
    {
        $id = $this->option('actor');
        if (! is_string($id) || ! Str::isUuid($id, 7)) {
            throw new RuntimeException('An authorized staff actor is required.');
        }
        $identity = app(IdentityReader::class)->contact($id, true);
        $roles = app(RoleAuthority::class);
        $permissions = $roles->permissionsFor($roles->roles($id));
        if ($identity === null || ! $identity->enabled || ! $identity->verifiedEmail || $identity->kind !== 'staff' || ! in_array('portfolio.manage', $permissions, true)) {
            throw new RuntimeException('An enabled, verified portfolio manager is required.');
        }

        return new PortfolioActor($id, $permissions, false);
    }
}
