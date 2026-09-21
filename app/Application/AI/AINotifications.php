<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Modules\AI\Contracts\AICompletionNotifier;
use App\Modules\AI\Models\AIRun;
use App\Modules\Notifications\Contracts\NotificationRecorder;
use Illuminate\Support\Str;

final readonly class AINotifications implements AICompletionNotifier
{
    public function __construct(private NotificationRecorder $notifications) {}

    public function completed(AIRun $run): void
    {
        if (! in_array($run->state, ['succeeded', 'failed'], true)) {
            return;
        }
        $this->notifications->record($run->actor_id, $run->state === 'succeeded' ? 'ai.suggestion_ready' : 'ai.failed',
            'ai_run', $run->id, $run->id, (string) Str::uuid7());
    }
}
