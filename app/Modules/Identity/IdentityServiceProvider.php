<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Infrastructure\Async\OperationHandlerRegistry;
use App\Modules\Identity\Authorization\Commands\BootstrapSuperAdminCommand;
use App\Modules\Identity\Contracts\IdentityReader;
use App\Modules\Identity\Recovery\Adapters\SmtpMailTransport;
use App\Modules\Identity\Recovery\Contracts\MailTransport;
use App\Modules\Identity\Security\DatabaseIdentityReader;
use App\Modules\Identity\Security\PruneIdentitySessions;
use App\Modules\Identity\Staff\ExpireInvitations;
use App\Modules\Identity\Staff\InvitationMailHandler;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IdentityReader::class, DatabaseIdentityReader::class);
        $this->app->bind(MailTransport::class, SmtpMailTransport::class);
    }

    public function boot(OperationHandlerRegistry $operations): void
    {
        Sanctum::getAccessTokenFromRequestUsing(static fn (): string => '');
        $operations->register('identity.recovery_mail', new RecoveryMailHandler($this->app));
        $operations->register('identity.staff_invitation_mail', new InvitationMailHandler($this->app));
        $this->commands([BootstrapSuperAdminCommand::class, PruneIdentitySessions::class, ExpireInvitations::class]);
    }
}
