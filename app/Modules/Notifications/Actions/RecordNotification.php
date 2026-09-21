<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Infrastructure\Async\OperationRecorder;
use App\Modules\Identity\Contracts\NotificationRecipientReader;
use App\Modules\Notifications\Contracts\NotificationRecorder;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\NotificationTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final readonly class RecordNotification implements NotificationRecorder
{
    public function __construct(private OperationRecorder $operations, private NotificationRecipientReader $recipients) {}

    public function record(string $recipientId, string $type, string $resourceType, string $resourceId, string $logicalKey, string $correlationId): string
    {
        if (DB::transactionLevel() === 0 || ! Str::isUuid($recipientId, 7) || ! Str::isUuid($resourceId, 7) || ! Str::isUuid($correlationId)
            || ! in_array($resourceType, ['project_request', 'proposal', 'project', 'ai_run'], true) || $logicalKey === '' || strlen($logicalKey) > 255) {
            throw new LogicException('Notification requires a business transaction and valid references.');
        }
        if ($this->recipients->current($recipientId) === null) {
            throw new LogicException('Notification recipient does not exist.');
        }
        $template = NotificationTemplates::for($type);
        $hash = hash('sha256', $type.'|'.$resourceType.'|'.$resourceId);
        $key = hash('sha256', $logicalKey);
        DB::table('notification_inboxes')->insertOrIgnore(['user_id' => $recipientId]);
        DB::table('notification_inboxes')->where('user_id', $recipientId)->lockForUpdate()->firstOrFail();
        $existing = Notification::query()->where('recipient_id', $recipientId)->where('type', $type)->where('logical_key_hash', $key)->first();
        if ($existing !== null) {
            if (! hash_equals($existing->input_hash, $hash)) {
                throw new LogicException('Notification key was reused for another resource.');
            }

            return $existing->id;
        }
        $id = (string) Str::uuid7();
        DB::table('notifications')->insert([...$template, 'id' => $id, 'recipient_id' => $recipientId, 'type' => $type,
            'resource_type' => $resourceType, 'resource_id' => $resourceId, 'logical_key_hash' => $key, 'input_hash' => $hash, 'correlation_id' => $correlationId]);
        DB::table('notification_inboxes')->where('user_id', $recipientId)->increment('lock_version');
        $delivery = (string) Str::uuid7();
        DB::table('notification_deliveries')->insert(['id' => $delivery, 'notification_id' => $id, 'state' => 'pending']);
        $operation = $this->operations->record('notifications.email', 'notification:'.$delivery.':1', ['delivery_id' => $delivery], $correlationId);
        DB::table('notification_deliveries')->where('id', $delivery)->update(['operation_id' => $operation->id]);

        return $id;
    }
}
