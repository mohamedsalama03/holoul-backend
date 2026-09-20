<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Modules\Documents\Actions\ManageDocuments;
use App\Modules\Documents\Actions\ProjectUploadExpiryCursor;
use App\Modules\Documents\DocumentPolicy;
use App\Modules\Documents\Models\Document;
use App\Modules\Projects\Actions\ProjectDocuments;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/** Metadata cleanup only: no storage I/O inside the coordinated transaction. */
final readonly class ExpireProjectUploads
{
    public function __construct(private ProjectDocuments $attachments, private ManageDocuments $documents,
        private ProjectUploadExpiryCursor $progress) {}

    /** @return array{inspected:int,expired:int} */
    public function handle(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Project upload expiry accepts 1 to 100 records.');
        }
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Project upload expiry requires independent transactions.');
        }
        $cursor = $this->progress->value();
        $query = Document::query()->where('state', 'uploading')
            ->whereIn('id', DB::table('project_document_uploads')->select('document_id'))
            ->whereRaw('created_at <= clock_timestamp() - make_interval(hours => ?)', [DocumentPolicy::ORPHAN_GRACE_HOURS])
            ->where('upload_expires_at', '<', DB::raw('clock_timestamp()'))->orderBy('id')->limit($limit);
        if ($cursor !== null) {
            $query->where('id', '>', $cursor);
        }
        $candidates = $query->get(['id', 'parent_id']);
        $correlation = (string) Str::uuid7();
        $expired = 0;
        foreach ($candidates as $candidate) {
            $expired += DB::transaction(function () use ($candidate, $correlation): int {
                $project = Project::query()->whereKey($candidate->parent_id)->lockForUpdate()->first();
                if ($project === null) {
                    return 0;
                }
                $document = Document::query()->whereKey($candidate->id)->where('parent_id', $project->id)
                    ->where('customer_id', $project->customer_id)->where('customer_user_id', $project->customer_user_id)
                    ->where('state', 'uploading')->where('upload_expires_at', '<', DB::raw('clock_timestamp()'))
                    ->whereRaw('created_at <= clock_timestamp() - make_interval(hours => ?)', [DocumentPolicy::ORPHAN_GRACE_HOURS])
                    ->lockForUpdate()->first();
                if ($document === null || DB::table('project_documents')->where('document_id', $document->id)->exists()) {
                    return 0;
                }
                $this->attachments->detachExpiredUpload($project, $document->id, $correlation);
                $this->documents->deleteRecord($document, $correlation);

                return 1;
            }, 2);
        }
        $this->progress->advance($cursor, $candidates->last()?->id);

        return ['inspected' => $candidates->count(), 'expired' => $expired];
    }
}
