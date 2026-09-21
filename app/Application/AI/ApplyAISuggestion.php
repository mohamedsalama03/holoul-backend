<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\AI\Models\AIRun;
use App\Modules\Discovery\Actions\ManageDiscovery;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\ProjectIntake\Actions\CommercialIntake;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Only an explicit HTTP human command reaches this owner-action composition. */
final readonly class ApplyAISuggestion
{
    public function __construct(private AISources $sources, private CurrentAIContext $current, private ManageDraft $drafts,
        private IntakeStore $intake, private CommercialIntake $commercial, private ManageDiscovery $discovery) {}

    /** @param array<string,mixed> $output */
    public function handle(AuthorizedIdentity $identity, AIRun $run, array $output, ?string $discoveryId, string $correlation): void
    {
        if (! $identity->allows($identity->kind === 'customer' ? 'ai.self.apply' : 'ai.apply')) {
            throw new AuthorizationException;
        }
        $this->current->verify($run);
        $actor = $this->sources->intakeActor($identity);
        if ($identity->kind === 'customer' && $run->parent_type === 'request' && $run->source_type === 'intake_draft'
            && in_array($run->purpose, ['improve_description', 'suggest_category'], true)) {
            if ($discoveryId !== null) {
                throw new HttpException(422);
            }
            $patch = $run->purpose === 'improve_description' ? ['project_description' => $output['description']]
                : ['category_id' => $output['category_id'], 'subcategory_id' => $output['subcategory_id']];
            $this->drafts->update($actor, $run->parent_id, VersionPrecondition::etag($run->parent_id, $run->source_version), $patch, $correlation);

            return;
        }
        if ($identity->kind !== 'staff' || $run->purpose !== 'extract_requirements' || $run->parent_type !== 'request'
            || $run->source_type !== 'intake_revision' || $discoveryId === null) {
            throw new HttpException(409);
        }
        $request = $this->intake->find($actor, $run->parent_id, true);
        $context = $this->commercial->context($request, $actor, $correlation, false);
        $revision = $this->discovery->find($context, $discoveryId);
        if ($revision->source_intake_revision_id !== $run->source_id || $revision->state === 'completed') {
            throw new HttpException(409);
        }
        // Preserve all existing mutable requirements; suggestions are explicitly appended as proposed.
        $requirements = [];
        foreach (DB::table('discovery_requirements')->where('revision_id', $discoveryId)->orderBy('position')->get() as $item) {
            $requirements[] = ['title' => $item->title, 'description' => $item->description, 'category' => $item->category,
                'priority' => $item->priority, 'notes' => $item->notes, 'status' => $item->status];
        }
        $suggestions = $output['requirements'] ?? null;
        if (! is_array($suggestions)) {
            throw new HttpException(409);
        }
        foreach ($suggestions as $suggestion) {
            if (! is_array($suggestion)) {
                throw new HttpException(409);
            }
            $requirements[] = ['title' => $suggestion['title'] ?? null, 'description' => $suggestion['description'] ?? null,
                'priority' => $suggestion['priority'] ?? null, 'category' => 'functional', 'notes' => '', 'status' => 'proposed'];
        }
        $this->discovery->requirements($context, $discoveryId, ['requirements' => $requirements]);
        $this->commercial->change($request, null, $identity->id, $correlation);
    }
}
