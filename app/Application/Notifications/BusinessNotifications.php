<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Modules\Notifications\Contracts\NotificationRecorder;
use App\Modules\ProjectIntake\Events\RequestChanged;
use App\Modules\Projects\Events\ProjectChanged;
use App\Modules\Proposals\Events\ProposalChanged;
use Illuminate\Support\Facades\DB;

/** Synchronous database-only subscriber; delivery itself occurs after commit. */
final readonly class BusinessNotifications
{
    public function __construct(private NotificationRecorder $notifications) {}

    public function intake(RequestChanged $event): void
    {
        $type = $event->event === 'information_requested' ? 'request.information_required' : 'request.submitted';
        $this->notifications->record($event->customerUserId, $type, 'project_request', $event->id,
            $event->id.':'.$event->version, $event->correlation);
        if ($type === 'request.submitted' && $event->assignedStaffId !== null) {
            $this->notifications->record($event->assignedStaffId, $type, 'project_request', $event->id,
                $event->id.':'.$event->version, $event->correlation);
        }
    }

    public function proposal(ProposalChanged $event): void
    {
        $request = DB::table('project_requests')->where('id', $event->requestId)->first();
        if ($request === null || ! is_string($request->customer_user_id)) {
            throw new \LogicException('Proposal notification requires its source request.');
        }
        $recipients = [$request->customer_user_id];
        if ($event->event !== 'issued' && is_string($request->assigned_staff_id)) {
            $recipients[] = $request->assigned_staff_id;
        }
        foreach (array_unique($recipients) as $recipient) {
            $this->notifications->record($recipient, 'proposal.'.$event->event, 'proposal', $event->id,
                $event->id.':'.$event->version, $event->correlation);
        }
    }

    public function project(ProjectChanged $event): void
    {
        $type = match ($event->event) {
            'created' => 'project.created',
            'update_published' => 'project.update_published',
            'milestone_updated' => 'project.milestone_updated',
            default => 'project.state_changed',
        };
        $this->notifications->record($event->customerUserId, $type, 'project', $event->id,
            $event->id.':'.$event->version, $event->correlation);
    }
}
