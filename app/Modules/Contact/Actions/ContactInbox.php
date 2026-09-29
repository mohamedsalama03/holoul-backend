<?php

declare(strict_types=1);

namespace App\Modules\Contact\Actions;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Contact\Data\ContactActor;
use App\Modules\Contact\Models\ContactMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ContactInbox
{
    public function __construct(private RecordAuditEvent $audit) {}

    /** @return array<string,mixed> */
    public function page(ContactActor $actor, int $limit, ?string $status, ?string $cursor, string $requestId): array
    {
        $actor->require('contact.read');
        $query = ContactMessage::query();
        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($cursor !== null) {
            try {
                $decoded = json_decode(Crypt::decryptString($cursor), true, 4, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = null;
            }
            if (! is_array($decoded) || ($decoded['status'] ?? null) !== $status || ! is_string($decoded['id'] ?? null) || ! Str::isUuid($decoded['id'], 7)) {
                throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
            }
            $query->where('id', '<', $decoded['id']);
        }
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $page = $rows->take($limit);
        $last = $page->last();
        $data = $page->map(fn (ContactMessage $message): array => $this->record($message, false))->values()->all();
        $next = $rows->count() > $limit && $last !== null ? Crypt::encryptString(json_encode(['id' => $last->id, 'status' => $status], JSON_THROW_ON_ERROR)) : null;
        $this->audit->handle('contact.inbox_read', 'user', $actor->id, $requestId, $actor->id);

        return ['data' => $data, 'meta' => ['next_cursor' => $next, 'limit' => $limit]];
    }

    public function find(ContactActor $actor, string $id, string $permission = 'contact.read'): ContactMessage
    {
        $actor->require($permission);
        if (! Str::isUuid($id, 7)) {
            throw new HttpException(404);
        }

        return ContactMessage::query()->whereKey($id)->lockForUpdate()->first() ?? throw new HttpException(404);
    }

    /** @return array<string,mixed> */
    public function read(ContactActor $actor, ContactMessage $message, string $requestId): array
    {
        $actor->require('contact.read');
        $this->audit->handle('contact.message_read', 'contact.message', $message->id, $requestId, $actor->id);

        return $this->record($message, true);
    }

    public function change(ContactActor $actor, string $id, ?string $etag, ?string $status, string $requestId): ContactMessage
    {
        $redact = $status === null;
        $message = $this->find($actor, $id, $redact ? 'contact.redact' : 'contact.manage');
        VersionPrecondition::require($etag, $message->id, $message->lock_version);
        if ($message->status === 'redacted') {
            throw new HttpException(409);
        }
        if ($redact) {
            $message->forceFill(['full_name' => null, 'email' => null, 'phone' => null, 'company' => null, 'message' => null,
                'status' => 'redacted', 'redacted_at' => now()]);
        } else {
            if (! in_array($status, ['received', 'in_progress', 'resolved', 'spam'], true)) {
                throw ValidationException::withMessages(['status' => 'Invalid status.']);
            }
            $message->status = $status;
        }
        $message->lock_version++;
        $message->updated_at = now()->toImmutable();
        $message->save();
        $this->audit->handle($redact ? 'contact.redacted' : 'contact.status_changed', 'contact.message', $message->id, $requestId, $actor->id);

        return $message;
    }

    /** @return array<string,mixed> */
    public function record(ContactMessage $message, bool $detail): array
    {
        $data = ['id' => $message->id, 'reference' => $message->reference, 'full_name' => $message->full_name, 'email' => $message->email,
            'status' => $message->status, 'received_at' => $message->received_at->toISOString(), 'updated_at' => $message->updated_at->toISOString(),
            'redacted_at' => $message->redacted_at?->toISOString(), 'etag' => VersionPrecondition::etag($message->id, $message->lock_version)];
        if ($detail) {
            $delivery = DB::table('contact_deliveries')->where('message_id', $message->id)->value('state');
            $data += ['phone' => $message->phone, 'company' => $message->company, 'message' => $message->message,
                'delivery_status' => is_string($delivery) ? $delivery : 'pending'];
        }

        return $data;
    }
}
