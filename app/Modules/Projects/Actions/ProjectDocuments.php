<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Data\DocumentState;
use App\Modules\Documents\Data\DocumentView;
use App\Modules\Documents\Data\StoredObject;
use App\Modules\Documents\Data\UploadReservation;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Owns project attachments; byte handling and lifecycle remain in Documents. */
final readonly class ProjectDocuments
{
    public function __construct(private ProjectStore $store, private DocumentService $documents, private RecordAuditEvent $audit) {}

    public function owner(Project $project, ProjectActor $actor, string $correlation): DocumentOwner
    {
        return new DocumentOwner($project->id, $project->customer_id, $project->customer_user_id, $actor->id, $correlation);
    }

    public function reserve(Project $project, ProjectActor $actor, ?string $etag, string $filename, int $bytes,
        string $sha256, string $visibility, string $key, string $correlation): UploadReservation
    {
        $this->editable($project, $actor);
        if (! in_array($visibility, ['internal', 'customer'], true)) {
            throw ValidationException::withMessages(['visibility' => 'Explicit document visibility is required.']);
        }
        $owner = $this->owner($project, $actor, $correlation);
        $reservation = $this->documents->reserve($owner, $filename, $bytes, $sha256, $key);
        foreach (['project_document_uploads', 'project_documents'] as $table) {
            $existing = DB::table($table)->where('project_id', $project->id)->where('document_id', $reservation->documentId)->first();
            if ($existing !== null) {
                if ($existing->visibility !== $visibility) {
                    throw new HttpException(409);
                }

                return $reservation;
            }
        }
        VersionPrecondition::require($etag, $project->id, $project->lock_version);
        if ($this->documents->metadata($owner, $reservation->documentId, true)->state !== DocumentState::Uploading) {
            throw new HttpException(409);
        }
        DB::table('project_document_uploads')->insert(['document_id' => $reservation->documentId,
            'project_id' => $project->id, 'customer_id' => $project->customer_id, 'visibility' => $visibility, 'attached_by' => $actor->id]);
        $this->store->changed($project);
        $this->store->event($project, 'document_reserved', $actor, $correlation);

        return $reservation;
    }

    public function upload(Project $project, ProjectActor $actor, string $id, ?string $etag, string $correlation): UploadReservation
    {
        $this->editable($project, $actor);
        VersionPrecondition::require($etag, $project->id, $project->lock_version);
        $this->attachment($project, $actor, $id);

        return $this->documents->uploadReservation($this->owner($project, $actor, $correlation), $id);
    }

    public function finalize(Project $project, ProjectActor $actor, string $id, StoredObject $object, ?string $etag,
        string $correlation): DocumentView
    {
        $this->upload($project, $actor, $id, $etag, $correlation);
        $view = $this->documents->finalize($this->owner($project, $actor, $correlation), $id, $object);
        $pending = DB::table('project_document_uploads')->where('project_id', $project->id)->where('document_id', $id)->first();
        if ($pending !== null) {
            DB::table('project_documents')->insert(['document_id' => $id, 'project_id' => $project->id,
                'customer_id' => $project->customer_id, 'visibility' => $pending->visibility, 'attached_by' => $pending->attached_by]);
            DB::table('project_document_uploads')->where('document_id', $id)->delete();
            // The attachment was versioned on reservation. File processing has
            // its own version, just as B4 finalization and scanner completion do.
            $this->store->event($project, 'document_attached', $actor, $correlation);
        }

        return $view;
    }

    public function remove(Project $project, ProjectActor $actor, string $id, ?string $etag, string $correlation): DocumentView
    {
        $this->editable($project, $actor);
        VersionPrecondition::require($etag, $project->id, $project->lock_version);
        $this->attachment($project, $actor, $id);
        $owner = $this->owner($project, $actor, $correlation);
        if ($this->documents->metadata($owner, $id, true)->state !== DocumentState::Uploading
            || ! DB::table('project_document_uploads')->where('project_id', $project->id)->where('document_id', $id)->exists()) {
            throw new HttpException(409);
        }
        DB::table('project_document_uploads')->where('document_id', $id)->delete();
        $view = $this->documents->requestDeletion($owner, $id);
        $this->store->changed($project);
        $this->store->event($project, 'document_upload_cancelled', $actor, $correlation);

        return $view;
    }

    public function retry(Project $project, ProjectActor $actor, string $id, ?string $etag, string $correlation): DocumentView
    {
        $this->editable($project, $actor);
        VersionPrecondition::require($etag, $project->id, $project->lock_version);
        $this->attachment($project, $actor, $id);
        $view = $this->documents->retryScan($this->owner($project, $actor, $correlation), $id);
        $this->store->changed($project);
        $this->store->event($project, 'document_scan_retry_requested', $actor, $correlation);

        return $view;
    }

    public function readable(Project $project, ProjectActor $actor, string $id): string
    {
        $this->authorizeRead($project, $actor);
        $attachment = $this->attachment($project, $actor, $id);
        $visibility = $attachment->visibility;

        return is_string($visibility) ? $visibility : throw new HttpException(404);
    }

    /** Maintenance holds the parent and expired Uploading document locks. */
    public function detachExpiredUpload(Project $project, string $id, string $correlation): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Project upload expiry requires the coordinated transaction.');
        }
        if (DB::table('project_documents')->where('document_id', $id)->exists()) {
            throw new HttpException(409);
        }
        $deleted = DB::table('project_document_uploads')->where('project_id', $project->id)
            ->where('customer_id', $project->customer_id)->where('document_id', $id)->delete();
        if ($deleted !== 0) {
            if (! in_array($project->state, ['completed', 'cancelled'], true)) {
                $this->store->changed($project);
            }
            DB::table('project_activity')->insert(['id' => (string) Str::uuid7(), 'project_id' => $project->id,
                'event' => 'document_upload_expired', 'actor_id' => null, 'entity_version' => $project->lock_version,
                'correlation_id' => $correlation]);
            $this->audit->handle('projects.document_upload_expired', 'projects.project', $project->id, $correlation);
        }
    }

    /** @return array{data:list<array<string,mixed>>,meta:array{page:int,per_page:int,total:int}} */
    public function listing(Project $project, ProjectActor $actor, string $correlation, int $page, int $perPage): array
    {
        $this->authorizeRead($project, $actor);
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw ValidationException::withMessages(['page' => 'A bounded document page is required.']);
        }
        $query = DB::table('project_documents')->select('document_id', 'visibility')->where('project_id', $project->id);
        if ($actor->customerId !== null) {
            $query->where('visibility', 'customer');
        } else {
            $query->unionAll(DB::table('project_document_uploads')->select('document_id', 'visibility')->where('project_id', $project->id));
        }
        $rows = DB::query()->fromSub($query, 'attachments')->orderBy('document_id')->paginate($perPage, ['*'], 'page', $page);
        $attachments = [];
        foreach ($rows->items() as $row) {
            if ($row instanceof stdClass && is_string($row->document_id) && is_string($row->visibility)) {
                $attachments[$row->document_id] = $row->visibility;
            }
        }
        $documents = $this->documents->metadataMany($this->owner($project, $actor, $correlation), array_keys($attachments));
        $data = [];
        foreach ($attachments as $id => $visibility) {
            $data[] = [...$documents[$id]->toArray(), 'visibility' => $visibility];
        }

        return ['data' => $data, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $rows->total()]];
    }

    private function editable(Project $project, ProjectActor $actor): void
    {
        $this->store->staff($actor, $project, 'projects.documents.upload');
        $this->store->active($project);
    }

    private function authorizeRead(Project $project, ProjectActor $actor): void
    {
        if ($actor->customerId === null) {
            $this->store->staff($actor, $project, 'projects.documents.read');
        } else {
            $this->store->owner($actor, $project, 'projects.self.documents.read');
        }
    }

    private function attachment(Project $project, ProjectActor $actor, string $id): stdClass
    {
        if (! Str::isUuid($id, 7)) {
            throw new HttpException(404);
        }
        $query = DB::table('project_documents')->where('project_id', $project->id)->where('customer_id', $project->customer_id)->where('document_id', $id);
        if ($actor->customerId !== null) {
            $query->where('visibility', 'customer');
        }
        $attachment = $query->first();
        if ($attachment === null && $actor->customerId === null) {
            $attachment = DB::table('project_document_uploads')->where('project_id', $project->id)->where('customer_id', $project->customer_id)->where('document_id', $id)->first();
        }

        return $attachment ?? throw new HttpException(404);
    }
}
