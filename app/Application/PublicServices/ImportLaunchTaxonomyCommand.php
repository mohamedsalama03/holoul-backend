<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Modules\Categories\Actions\ImportLaunchTaxonomy;
use App\Modules\Categories\Data\TaxonomyActor;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Contracts\IdentityReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ImportLaunchTaxonomyCommand extends Command
{
    protected $signature = 'taxonomy:prepare-launch {--apply : Add missing rows to the local/testing database} {--actor= : Existing authorized staff UUID for audit}';

    protected $description = 'Inspect or add the approved 12/49 catalogue without altering existing taxonomy.';

    public function handle(ImportLaunchTaxonomy $import, IdentityReader $identities, RoleAuthority $roles): int
    {
        $apply = $this->option('apply') === true;
        if ($apply && ! app()->environment(['local', 'testing'])) {
            $this->error('Production catalogue changes require the reviewed cutover procedure.');

            return self::FAILURE;
        }
        $result = DB::transaction(function () use ($apply, $identities, $roles, $import): ?array {
            $actor = null;
            if ($apply) {
                $id = $this->option('actor');
                if (! is_string($id) || ! Str::isUuid($id, 7)) {
                    return null;
                }
                $identity = $identities->contact($id, true);
                if ($identity === null || ! $identity->enabled || ! $identity->emailPrerequisiteSatisfied || $identity->kind !== 'staff'
                    || ! in_array('taxonomy.manage', $roles->permissionsFor($roles->roles($id)), true)) {
                    return null;
                }
                $actor = new TaxonomyActor($id, true);
            }

            return $import->handle($actor, $apply);
        });
        if ($result === null) {
            $this->error('An enabled, verified catalogue manager is required.');

            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
