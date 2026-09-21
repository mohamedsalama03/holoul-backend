<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Infrastructure\Async\OperationHandlerRegistry;
use App\Modules\Notifications\Actions\RecordNotification;
use App\Modules\Notifications\Adapters\SmtpEmailProvider;
use App\Modules\Notifications\Contracts\EmailProvider;
use App\Modules\Notifications\Contracts\NotificationRecorder;
use Illuminate\Support\ServiceProvider;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationRecorder::class, RecordNotification::class);
        $this->app->bind(EmailProvider::class, SmtpEmailProvider::class);
    }

    public function boot(OperationHandlerRegistry $operations): void
    {
        $operations->register('notifications.email', new NotificationOperationHandler($this->app));
        $this->commands([ReconcileNotificationsCommand::class]);
    }
}
