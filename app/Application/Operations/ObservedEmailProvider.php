<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Infrastructure\Operations\MetricRecorder;
use App\Modules\Notifications\Contracts\EmailProvider;
use App\Modules\Notifications\Data\EmailMessage;

final readonly class ObservedEmailProvider implements EmailProvider
{
    public function __construct(private EmailProvider $inner, private MetricRecorder $metrics) {}

    public function send(EmailMessage $message): ?string
    {
        return $this->metrics->provider('notifications', fn (): ?string => $this->inner->send($message));
    }
}
