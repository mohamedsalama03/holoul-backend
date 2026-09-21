<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use InvalidArgumentException;

final class NotificationTemplates
{
    /** @return array{title:string,message:string} */
    public static function for(string $type): array
    {
        $title = match ($type) {
            'request.submitted' => 'Project request submitted',
            'request.information_required' => 'More information requested',
            'proposal.issued' => 'A proposal is ready for review',
            'proposal.accepted' => 'Proposal accepted',
            'proposal.declined' => 'Proposal declined',
            'project.created' => 'Project created',
            'project.update_published' => 'A project update is available',
            'project.milestone_updated' => 'Project milestone updated',
            'project.state_changed' => 'Project progress updated',
            'ai.suggestion_ready' => 'Your AI suggestion is ready',
            'ai.failed' => 'Your AI request needs attention',
            default => throw new InvalidArgumentException('Unsupported notification template.'),
        };

        return ['title' => $title, 'message' => 'Sign in to HOLOUL to review this update.'];
    }
}
