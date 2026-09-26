<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Documents\Data\DocumentState;
use App\Modules\Documents\DocumentPolicy;
use App\Modules\Documents\Models\Document;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Called in the same transaction as the owner module's append-only claim. */
final readonly class ClaimGuestDocuments
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(string $requestId, string $customerId, string $userId, string $correlation): void
    {
        ManageDocuments::requireTransaction();
        DB::table('document_quotas')->insertOrIgnore(['customer_id' => $customerId]);
        DB::table('document_quotas')->where('customer_id', $customerId)->lockForUpdate()->first();
        $documents = Document::query()->where('guest_request_id', $requestId)->whereNull('customer_id')->orderBy('id')->lockForUpdate()->get();
        if ($documents->isEmpty()) {
            return;
        }
        $active = Document::query()->where('customer_id', $customerId)->where('state', '<>', DocumentState::Deleted->value)->get(['id', 'expected_size']);
        $incoming = $documents->filter(fn (Document $document): bool => $document->state !== DocumentState::Deleted);
        $bytes = $active->reduce(fn (int $sum, Document $document): int => $sum + $document->expected_size, 0)
            + $incoming->reduce(fn (int $sum, Document $document): int => $sum + $document->expected_size, 0);
        if ($bytes > DocumentPolicy::MAX_RESERVED_BYTES || $active->count() + $incoming->count() > DocumentPolicy::MAX_DOCUMENTS) {
            throw new HttpException(429, headers: ['Retry-After' => '600']);
        }
        foreach ($documents as $document) {
            $document->forceFill(['customer_id' => $customerId, 'customer_user_id' => $userId, 'lock_version' => $document->lock_version + 1])->save();
            $this->audit->handle('documents.guest_claimed', 'documents.document', $document->id, $correlation, $userId);
        }
    }
}
