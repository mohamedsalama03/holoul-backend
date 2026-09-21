<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Infrastructure\Async\OperationRecorder;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class NotificationAccess
{
    public function __construct(private RecordAuditEvent $audit, private OperationRecorder $operations) {}

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function handle(AuthorizedIdentity $actor, string $operation, string $id, array $input, ?string $etag, ?string $key, string $correlation): array
    {
        $admin = in_array($operation, ['deliveries', 'delivery', 'replay'], true);
        $write = in_array($operation, ['read', 'read_all', 'preferences_update', 'replay'], true);
        $permission = $admin ? ($write ? 'notifications.delivery.replay' : 'notifications.delivery.read') : ($write ? 'notifications.self.manage' : 'notifications.self.read');
        if (! $actor->allows($permission) || ($admin && $actor->kind !== 'staff')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $operation, $id, $input, $etag, $key, $correlation, $write, $admin): array {
            $after = $input['after'] ?? null;
            $limit = $input['limit'] ?? 25;
            if (($after !== null && (! is_string($after) || ! Str::isUuid($after, 7))) || ! is_int($limit) || $limit < 1 || $limit > 100) {
                throw new HttpException(422);
            }
            DB::table('notification_inboxes')->insertOrIgnore(['user_id' => $actor->id]);
            $inbox = DB::table('notification_inboxes')->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            if (! is_int($inbox->lock_version)) {
                throw new LogicException('Invalid inbox version.');
            }
            if ($write) {
                $replay = $this->receipt($actor->id, $operation, $id, $input, $etag, $key);
                if ($replay !== null) {
                    return $replay;
                }
            }
            if ($operation === 'deliveries') {
                $query = NotificationDelivery::query()->orderBy('id');
                if ($after !== null) {
                    $query->where('id', '>', $after);
                }
                if (isset($input['state'])) {
                    $query->where('state', $input['state']);
                }
                $rows = $query->limit($limit + 1)->get(['id', 'notification_id', 'state', 'generation', 'lock_version', 'failure_code', 'created_at', 'completed_at']);

                return ['data' => $rows->take($limit)->toArray(), 'meta' => ['next_after' => $rows->count() > $limit ? $rows[$limit - 1]?->id : null]];
            }
            if ($admin) {
                if (! Str::isUuid($id, 7)) {
                    throw new HttpException(404);
                }
                $delivery = NotificationDelivery::query()->whereKey($id)->lockForUpdate()->first() ?? throw new HttpException(404);
                if ($operation === 'replay') {
                    VersionPrecondition::require($etag, $id, $delivery->lock_version);
                    $this->replayDelivery($actor, $delivery, $input, $correlation);
                }
                $result = ['data' => [...$delivery->only(['id', 'notification_id', 'state', 'generation', 'lock_version', 'failure_code', 'created_at', 'completed_at']),
                    'attempts' => DB::table('notification_delivery_attempts')->where('delivery_id', $id)->orderBy('generation')->orderBy('attempt_number')
                        ->get(['id', 'generation', 'attempt_number', 'state', 'failure_code', 'provider_reference', 'started_at', 'completed_at'])->all()],
                    'etag' => VersionPrecondition::etag($id, $delivery->lock_version)];
                // Stable small replay receipt excludes the growing attempt list.
                if ($write) {
                    $result['data'] = $delivery->only(['id', 'state', 'generation', 'lock_version']);
                }
            } elseif (in_array($operation, ['preferences', 'preferences_update'], true)) {
                DB::table('notification_preferences')->insertOrIgnore(['user_id' => $actor->id]);
                $preference = DB::table('notification_preferences')->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
                if (! is_int($preference->lock_version) || ! is_bool($preference->workflow_email)) {
                    throw new LogicException('Invalid notification preference.');
                }
                if ($write) {
                    VersionPrecondition::require($etag, $actor->id, $preference->lock_version);
                    if (! is_bool($input['workflow_email'] ?? null)) {
                        throw new HttpException(422);
                    }
                    $preference->workflow_email = $input['workflow_email'];
                    $preference->lock_version++;
                    DB::table('notification_preferences')->where('user_id', $actor->id)->update(['workflow_email' => $preference->workflow_email, 'lock_version' => $preference->lock_version]);
                    $this->audit->handle('notifications.preferences_changed', 'notifications.preferences', $actor->id, $correlation, $actor->id);
                }
                $result = ['data' => ['workflow_email' => $preference->workflow_email, 'security_messages_required' => true], 'etag' => VersionPrecondition::etag($actor->id, $preference->lock_version)];
            } elseif (in_array($operation, ['detail', 'read'], true)) {
                if (! Str::isUuid($id, 7)) {
                    throw new HttpException(404);
                }
                $notice = Notification::query()->whereKey($id)->where('recipient_id', $actor->id)->lockForUpdate()->first() ?? throw new HttpException(404);
                if ($write) {
                    VersionPrecondition::require($etag, $id, $notice->lock_version);
                    if ($notice->read_at === null) {
                        $notice->forceFill(['read_at' => now(), 'lock_version' => 2])->save();
                        DB::table('notification_inboxes')->where('user_id', $actor->id)->increment('lock_version');
                    }
                }
                $result = ['data' => $notice->only(['id', 'type', 'title', 'message', 'resource_type', 'resource_id', 'created_at', 'read_at', 'lock_version']), 'etag' => VersionPrecondition::etag($id, $notice->lock_version)];
            } elseif ($operation === 'read_all') {
                VersionPrecondition::require($etag, $actor->id, $inbox->lock_version);
                $changed = DB::table('notifications')->where('recipient_id', $actor->id)->whereNull('read_at')->update(['read_at' => DB::raw('clock_timestamp()'), 'lock_version' => 2]);
                if ($changed > 0) {
                    DB::table('notification_inboxes')->where('user_id', $actor->id)->increment('lock_version');
                    $inbox->lock_version++;
                }
                $result = ['data' => ['marked_read' => $changed], 'etag' => VersionPrecondition::etag($actor->id, $inbox->lock_version)];
            } elseif ($operation === 'unread') {
                $result = ['data' => ['unread' => DB::table('notifications')->where('recipient_id', $actor->id)->whereNull('read_at')->count()], 'etag' => VersionPrecondition::etag($actor->id, $inbox->lock_version)];
            } else {
                $query = Notification::query()->where('recipient_id', $actor->id)->orderBy('id');
                if ($after !== null) {
                    $query->where('id', '>', $after);
                }
                $rows = $query->limit($limit + 1)->get(['id', 'type', 'title', 'message', 'resource_type', 'resource_id', 'created_at', 'read_at', 'lock_version']);
                $result = ['data' => $rows->take($limit)->toArray(), 'meta' => ['next_after' => $rows->count() > $limit ? $rows[$limit - 1]?->id : null], 'etag' => VersionPrecondition::etag($actor->id, $inbox->lock_version)];
            }
            if ($write) {
                $this->saveReceipt($actor->id, $operation, $id, $input, $etag, $key, $result);
            }

            return $result;
        });
    }

    /** @param array<string,mixed> $input */
    private function replayDelivery(AuthorizedIdentity $actor, NotificationDelivery $delivery, array $input, string $correlation): void
    {
        if (! in_array($delivery->state, ['failed', 'uncertain'], true) || $delivery->generation >= 100) {
            throw new HttpException(409);
        }
        $reason = $input['reason'] ?? null;
        $resolution = $input['resolution'] ?? null;
        if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 1000 || ! in_array($resolution, ['retry_failure', 'confirmed_not_accepted'], true)
            || ($delivery->state === 'uncertain' && $resolution !== 'confirmed_not_accepted')) {
            throw new HttpException(422);
        }
        if (DB::table('async_operations')->where('id', $delivery->operation_id)->where('state', 'running')->where('lease_expires_at', '>', DB::raw('clock_timestamp()'))->exists()) {
            throw new HttpException(409);
        }
        $generation = $delivery->generation + 1;
        DB::table('notification_replays')->insert(['id' => (string) Str::uuid7(), 'delivery_id' => $delivery->id, 'actor_id' => $actor->id,
            'generation' => $generation, 'prior_state' => $delivery->state, 'resolution' => $resolution, 'reason' => $reason, 'correlation_id' => $correlation]);
        $operation = $this->operations->record('notifications.email', 'notification:'.$delivery->id.':'.$generation, ['delivery_id' => $delivery->id], $correlation);
        $delivery->forceFill(['state' => 'pending', 'generation' => $generation, 'operation_id' => $operation->id,
            'send_fence' => null, 'processing_at' => null, 'completed_at' => null, 'failure_code' => null, 'lock_version' => $delivery->lock_version + 1])->save();
        $this->audit->handle('notifications.delivery_replayed', 'notifications.delivery', $delivery->id, $correlation, $actor->id);
    }

    /** @param array<string,mixed> $input
     * @return ?array<string,mixed>
     */
    private function receipt(string $actor, string $operation, string $id, array $input, ?string $etag, ?string $key): ?array
    {
        $hash = $this->fingerprint($id, $input, $etag, $key);
        $row = DB::table('notification_command_keys')->where('actor_id', $actor)->where('operation', $operation)->where('key_hash', hash('sha256', $key ?? ''))
            ->select('*')->selectRaw('expires_at<=clock_timestamp() AS expired')->first();
        if ($row === null) {
            return null;
        }
        if ($row->expired === true || $row->input_hash !== $hash || ! is_string($row->result)) {
            throw new HttpException(409);
        }
        $result = json_decode($row->result, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($result)) {
            throw new LogicException('Invalid notification receipt.');
        }

        /** @var array<string,mixed> $result */
        return $result;
    }

    /** @param array<string,mixed> $input
     * @param  array<string,mixed>  $result
     */
    private function saveReceipt(string $actor, string $operation, string $id, array $input, ?string $etag, ?string $key, array $result): void
    {
        DB::table('notification_command_keys')->insert(['id' => (string) Str::uuid7(), 'actor_id' => $actor, 'operation' => $operation,
            'key_hash' => hash('sha256', $key ?? ''), 'input_hash' => $this->fingerprint($id, $input, $etag, $key), 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);
    }

    /** @param array<string,mixed> $input */
    private function fingerprint(string $id, array $input, ?string $etag, ?string $key): string
    {
        if ($key === null || preg_match('/\A[A-Za-z0-9_.:-]{16,128}\z/D', $key) !== 1) {
            throw new HttpException(422);
        }
        ksort($input);

        return hash('sha256', json_encode([$id, $input, $etag], JSON_THROW_ON_ERROR));
    }
}
