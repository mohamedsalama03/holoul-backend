<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Modules\Documents\Actions\ManageDocuments;
use App\Modules\Documents\Actions\UploadExpiryCursor;
use App\Modules\Documents\DocumentPolicy;
use App\Modules\Documents\Models\Document;
use App\Modules\ProjectIntake\Actions\IntakeDocuments;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/** Cross-module maintenance coordinates owned writes; it never performs storage I/O. */
final readonly class ExpireIntakeUploads
{
    public function __construct(private IntakeDocuments $attachments, private ManageDocuments $documents,
        private UploadExpiryCursor $progress) {}

    /** @return array{inspected:int,expired:int} */
    public function handle(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Upload expiry accepts 1 to 100 records.');
        }
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Upload expiry requires independent transactions.');
        }
        $cursor = $this->progress->value();
        $query = Document::query()->where('state', 'uploading')
            ->whereRaw('created_at <= clock_timestamp() - make_interval(hours => ?)', [DocumentPolicy::ORPHAN_GRACE_HOURS])
            ->where('upload_expires_at', '<', DB::raw('clock_timestamp()'))->orderBy('id')->limit($limit);
        if (is_string($cursor)) {
            $query->where('id', '>', $cursor);
        }
        $candidates = $query->get(['id', 'parent_id']);
        $requestId = (string) Str::uuid7();
        $expired = 0;
        foreach ($candidates as $candidate) {
            $expired += DB::transaction(function () use ($candidate, $requestId): int {
                // API mutations hold the same parent before the document. A
                // concurrent finalization or replacement must finish first.
                $parent = ProjectRequest::query()->whereKey($candidate->parent_id)->lockForUpdate()->first();
                if ($parent === null) {
                    return 0;
                }
                $document = Document::query()->whereKey($candidate->id)->where('parent_id', $parent->id)
                    ->where('customer_id', $parent->customer_id)->where('customer_user_id', $parent->customer_user_id)
                    ->where('state', 'uploading')
                    ->whereRaw('created_at <= clock_timestamp() - make_interval(hours => ?)', [DocumentPolicy::ORPHAN_GRACE_HOURS])
                    ->where('upload_expires_at', '<', DB::raw('clock_timestamp()'))->lockForUpdate()->first();
                if ($document === null || DB::table('intake_revision_documents')->where('document_id', $document->id)->exists()) {
                    return 0;
                }
                $this->attachments->detachExpiredUpload($parent, $document->id, $requestId);
                $this->documents->deleteRecord($document, $requestId);

                return 1;
            }, 2);
        }
        // The cursor advances past ineligible/closed candidates and wraps on
        // an empty page. Concurrent invocations cannot overwrite newer progress.
        $this->progress->advance($cursor, $candidates->last()?->id);

        return ['inspected' => $candidates->count(), 'expired' => $expired];
    }
}
