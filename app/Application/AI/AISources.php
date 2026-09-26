<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Modules\AI\Data\AISource;
use App\Modules\AI\Models\AIRun;
use App\Modules\Categories\Contracts\TaxonomyReader;
use App\Modules\Customers\Contracts\CustomerContactReader;
use App\Modules\Documents\Contracts\DocumentTextService;
use App\Modules\Documents\Data\DocumentTextSource;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\ProjectIntake\Actions\IntakeDocuments;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\ProjectIntake\Models\RequestRevision;
use App\Modules\Projects\Actions\ProjectDocuments;
use App\Modules\Projects\Actions\ProjectStore;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Only this application boundary constructs AI sources from authorized owner records. */
final readonly class AISources
{
    public function __construct(private CustomerContactReader $contacts, private IntakeStore $intake,
        private IntakeDocuments $intakeDocuments, private ProjectStore $projects, private ProjectDocuments $projectDocuments,
        private DocumentTextService $documents, private TaxonomyReader $taxonomy) {}

    public function intakeActor(AuthorizedIdentity $identity): IntakeActor
    {
        $contact = $identity->kind === 'customer' ? $this->contacts->currentForIdentity($identity->id) : null;
        if ($identity->kind === 'customer' && $contact === null) {
            throw new AuthorizationException;
        }

        return new IntakeActor($identity->id, $contact?->customerId, $identity->verifiedEmail, $identity->permissions);
    }

    public function access(AuthorizedIdentity $identity, string $parentType, string $parentId): ProjectRequest|Project
    {
        if (! $identity->verifiedEmail || ! $identity->allows($identity->kind === 'customer' ? 'ai.self.use' : 'ai.use')) {
            throw new AuthorizationException;
        }
        $actor = $this->intakeActor($identity);

        return match ($parentType) {
            'request' => $this->intake->find($actor, $parentId, true),
            'project' => $this->projects->find($this->projectActor($actor), $parentId),
            default => throw new HttpException(422),
        };
    }

    public function capture(AuthorizedIdentity $identity, string $parentType, string $parentId, string $purpose,
        ?string $documentId, string $correlation, bool $includeTaxonomy = true): AISource
    {
        $parent = $this->access($identity, $parentType, $parentId);
        $actor = $this->intakeActor($identity);
        $document = null;
        $text = '';
        if ($parent instanceof Project) {
            if ($documentId === null || ! in_array($purpose, ['analyze_document', 'extract_requirements', 'missing_information'], true)) {
                throw new HttpException(422);
            }
            $projectActor = $this->projectActor($actor);
            $this->projectDocuments->readable($parent, $projectActor, $documentId);
            $document = $this->documents->capture($this->projectDocuments->owner($parent, $projectActor, $correlation), $documentId);
            $sourceType = 'project_document';
            $sourceId = $documentId;
        } elseif ($identity->kind === 'customer') {
            $draft = $this->intakeDocuments->editable($parent, $actor);
            $sourceType = 'intake_draft';
            $sourceId = $draft->id;
            $text = $draft->project_description ?? '';
            if ($documentId !== null) {
                if (! DB::table('intake_draft_documents')->where('draft_id', $draft->id)->where('document_id', $documentId)->exists()) {
                    throw new HttpException(404);
                }
                $document = $this->documents->capture($this->intakeDocuments->owner($parent, $actor, $correlation), $documentId);
            }
        } else {
            if (in_array($purpose, ['improve_description', 'suggest_category'], true)) {
                throw new AuthorizationException;
            }
            if (in_array($parent->state, [RequestState::Draft, RequestState::Converted, RequestState::Withdrawn, RequestState::Rejected], true)) {
                throw new HttpException(409);
            }
            $revision = RequestRevision::query()->where('request_id', $parent->id)->whereKey($parent->latest_revision_id)->first() ?? throw new HttpException(409);
            $sourceType = 'intake_revision';
            $sourceId = $revision->id;
            $text = $revision->project_description;
            if ($documentId !== null) {
                $this->intakeDocuments->readable($parent, $actor, $documentId, true);
                if (! DB::table('intake_revision_documents')->where('revision_id', $revision->id)->where('document_id', $documentId)->exists()) {
                    throw new HttpException(404);
                }
                $document = $this->documents->capture($this->intakeDocuments->owner($parent, $actor, $correlation), $documentId);
            }
        }
        if (($purpose === 'analyze_document' && $document === null)
            || ($document !== null && in_array($purpose, ['improve_description', 'suggest_category'], true))
            || ($document === null && trim($text) === '')) {
            throw new HttpException(422);
        }
        // A document run receives only extracted document text, never contact/budget/internal-note fields.
        if ($document !== null) {
            $text = '';
        }
        $hash = hash('sha256', json_encode([$parentType, $parentId, $sourceType, $sourceId, $parent->lock_version,
            $text, $document?->toArray()], JSON_THROW_ON_ERROR));

        if ($parent->customer_id === null) {
            throw new HttpException(409);
        }

        return new AISource($identity->id, $parent->customer_id, $parentType, $parentId, $sourceType, $sourceId,
            $parent->lock_version, $hash, $text, $documentId, $document?->sha256,
            $purpose === 'suggest_category' && $includeTaxonomy ? $this->choices() : [], $document?->storageVersion);
    }

    public function document(AuthorizedIdentity $identity, string $parentType, string $parentId, string $documentId, string $correlation): DocumentTextSource
    {
        $parent = $this->access($identity, $parentType, $parentId);
        $actor = $this->intakeActor($identity);
        if ($parent instanceof Project) {
            $projectActor = $this->projectActor($actor);
            $this->projectDocuments->readable($parent, $projectActor, $documentId);

            return $this->documents->capture($this->projectDocuments->owner($parent, $projectActor, $correlation), $documentId);
        }
        $this->intakeDocuments->readable($parent, $actor, $documentId, true);

        return $this->documents->capture($this->intakeDocuments->owner($parent, $actor, $correlation), $documentId);
    }

    /** Historical output requires current document access, without requiring an unchanged source. */
    public function authorizeHistory(AuthorizedIdentity $identity, AIRun $run): void
    {
        $parent = $this->access($identity, $run->parent_type, $run->parent_id);
        if ($run->document_id === null) {
            return;
        }
        $actor = $this->intakeActor($identity);
        if ($parent instanceof Project) {
            $this->projectDocuments->readable($parent, $this->projectActor($actor), $run->document_id);

            return;
        }
        $this->intakeDocuments->readable($parent, $actor, $run->document_id, true);
    }

    private function projectActor(IntakeActor $actor): ProjectActor
    {
        return new ProjectActor($actor->id, $actor->customerId, $actor->verifiedEmail, false, $actor->permissions);
    }

    /** @return list<array{category_id:string,subcategory_id:string,category_name:string,subcategory_name:string}> */
    private function choices(): array
    {
        $choices = [];
        foreach ($this->taxonomy->categories(100)->items as $category) {
            foreach ($this->taxonomy->subcategories($category->id, 100)->items as $subcategory) {
                $choices[] = ['category_id' => $category->id, 'subcategory_id' => $subcategory->id,
                    'category_name' => $category->name, 'subcategory_name' => $subcategory->name];
                if (count($choices) === 100) {
                    return $choices;
                }
            }
        }

        return $choices;
    }
}
