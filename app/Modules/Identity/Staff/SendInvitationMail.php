<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Infrastructure\Async\AsyncOperation;
use App\Infrastructure\Async\LostOperationLease;
use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Infrastructure\Async\PermanentOperationFailure;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Recovery\Contracts\MailTransport;
use App\Modules\Identity\Recovery\Data\RecoveryMessage;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final readonly class SendInvitationMail implements OperationHandler
{
    public function __construct(private MailTransport $transport, private RoleAuthority $authority,
        private StaffGrantPolicy $grants, private RecordAuditEvent $audit) {}

    /** @return Closure():void */
    public function execute(OperationClaim $operation): Closure
    {
        $mailId = $operation->references['mail_id'] ?? throw new PermanentOperationFailure('mail_record_missing');
        $message = DB::transaction(function () use ($operation, $mailId): ?RecoveryMessage {
            $this->fence($operation);
            $this->authority->lockChanges();
            $candidate = DB::table('identity_staff_invitation_mail')->where('id', $mailId)->where('operation_id', $operation->id)->first()
                ?? throw new PermanentOperationFailure('mail_record_missing');
            $invite = StaffInvitation::query()->whereKey($candidate->invitation_id)->lockForUpdate()->firstOrFail();
            $mail = DB::table('identity_staff_invitation_mail')->where('id', $mailId)->lockForUpdate()->first()
                ?? throw new PermanentOperationFailure('mail_record_missing');
            if ($mail->state === 'sending') {
                $this->uncertain($mailId, $operation);

                return null;
            }
            if ($mail->state === 'uncertain') {
                throw new PermanentOperationFailure('mail_delivery_uncertain');
            }
            if ($mail->state !== 'pending') {
                return null;
            }
            $issuer = User::query()->find($invite->invited_by);
            $allowed = $issuer !== null;
            if ($issuer !== null) {
                try {
                    $this->grants->check($issuer, $invite->offeredRoles());
                } catch (GrantDenied) {
                    $allowed = false;
                }
            }
            if (! $allowed || $invite->status !== 'pending' || $invite->expires_at->isPast() || $invite->generation !== $mail->generation
                || User::query()->where('email', $invite->email)->exists()) {
                DB::table('identity_staff_invitation_mail')->where('id', $mailId)->update(['state' => 'discarded']);
                $invite->lock_version++;
                $invite->save();
                $this->audit->handle('identity.staff_invitation.mail_discarded', 'identity.staff_invitation', $invite->id, $operation->requestId ?? $operation->id);

                return null;
            }
            $origin = rtrim(Config::string('app.url'), '/');
            $host = parse_url($origin, PHP_URL_HOST);
            if (! is_string($host) || parse_url($origin, PHP_URL_SCHEME) !== 'https'
                || parse_url($origin, PHP_URL_USER) !== null || parse_url($origin, PHP_URL_PASS) !== null
                || parse_url($origin, PHP_URL_PATH) !== null || parse_url($origin, PHP_URL_QUERY) !== null || parse_url($origin, PHP_URL_FRAGMENT) !== null) {
                throw new PermanentOperationFailure('invitation_origin_invalid');
            }
            // The token exists only in this process and the outgoing message.
            // A crashed/ambiguous SMTP attempt requires explicit resend.
            $token = bin2hex(random_bytes(32));
            $invite->token_hash = hash('sha256', $token);
            $invite->lock_version++;
            $invite->save();
            DB::table('identity_staff_invitation_mail')->where('id', $mailId)->update(['state' => 'sending', 'send_fence' => $operation->fence]);
            $body = "You have been invited to join HOLOUL.\n\nOpen this link to review your invitation and choose a password:\n\n".
                $origin.'/admin/invitation#token='.$token."\n\nExpires: ".$invite->expires_at->toIso8601String()."\n\nStaff access requires MFA enrollment.\nIf you did not expect this invitation, ignore this message.\n";
            $messageHost = preg_match('/\A[a-z0-9.-]+\z/Di', $host) === 1 ? $host : 'holoul.invalid';

            return new RecoveryMessage($mailId, $invite->email, 'Your HOLOUL staff invitation', $body, $mailId.'@'.$messageHost);
        });
        if ($message === null) {
            if (DB::table('identity_staff_invitation_mail')->where('id', $mailId)->where('state', 'uncertain')->exists()) {
                throw new PermanentOperationFailure('mail_delivery_uncertain');
            }

            return static function (): void {};
        }
        try {
            $this->transport->send($message);
        } catch (\Throwable) {
            DB::transaction(function () use ($mailId, $operation): void {
                $this->fence($operation);
                $this->uncertain($mailId, $operation);
            });
            throw new PermanentOperationFailure('mail_delivery_uncertain');
        }

        return function () use ($mailId, $operation): void {
            // OperationRunner owns the fenced transaction. Take the common lock
            // before invitation/mail rows, exactly as resend/revoke/accept do.
            $this->authority->lockChanges();
            $mail = DB::table('identity_staff_invitation_mail')->where('id', $mailId)->first() ?? throw new PermanentOperationFailure('mail_record_missing');
            $invite = StaffInvitation::query()->whereKey($mail->invitation_id)->lockForUpdate()->firstOrFail();
            $changed = DB::table('identity_staff_invitation_mail')->where('id', $mailId)->where('state', 'sending')->where('send_fence', $operation->fence)
                ->update(['state' => 'sent', 'sent_at' => DB::raw('clock_timestamp()')]);
            if ($changed !== 1) {
                throw new PermanentOperationFailure('mail_state_conflict');
            }
            $invite->send_count++;
            $invite->last_sent_at = now();
            $invite->lock_version++;
            $invite->save();
            $this->audit->handle('identity.staff_invitation.mail_sent', 'identity.staff_invitation', $invite->id, $operation->requestId ?? $operation->id);
        };
    }

    private function fence(OperationClaim $operation): void
    {
        if (AsyncOperation::query()->whereKey($operation->id)->where('state', 'running')->where('fence', $operation->fence)
            ->where('lease_expires_at', '>', DB::raw('clock_timestamp()'))->lockForUpdate()->first() === null) {
            throw new LostOperationLease;
        }
    }

    private function uncertain(string $mailId, OperationClaim $operation): void
    {
        $this->authority->lockChanges();
        $mail = DB::table('identity_staff_invitation_mail')->where('id', $mailId)->first() ?? throw new PermanentOperationFailure('mail_record_missing');
        $invite = StaffInvitation::query()->whereKey($mail->invitation_id)->lockForUpdate()->firstOrFail();
        if (DB::table('identity_staff_invitation_mail')->where('id', $mailId)->where('state', 'sending')->update(['state' => 'uncertain']) === 1) {
            $invite->lock_version++;
            $invite->save();
            $this->audit->handle('identity.staff_invitation.mail_uncertain', 'identity.staff_invitation_mail', $mailId, $operation->requestId ?? $operation->id);
        }
    }
}
