<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Recovery\Adapters\SmtpMailTransport;
use App\Modules\Identity\Recovery\Data\RecoveryMailPayload;
use App\Modules\Identity\Recovery\Data\RecoveryMessage;
use App\Modules\Identity\Recovery\MailDeliveryUncertain;
use App\Modules\Identity\Recovery\RecoveryMailTemplates;
use App\Modules\Identity\Recovery\RecoveryPurpose;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class RecoveryTransportTest extends TestCase
{
    /** @return iterable<string, array{bool, string, int, string, string, string|null, string|null}> */
    public static function insecureConfigurations(): iterable
    {
        yield 'sandbox external host' => [true, 'external.example.test', 1025, 'smtp', 'noreply@holoul.test', null, null];
        yield 'sandbox wrong port' => [true, 'mailpit', 25, 'smtp', 'noreply@holoul.test', null, null];
        yield 'sandbox wrong sender' => [true, 'mailpit', 1025, 'smtp', 'real@example.test', null, null];
        yield 'external opportunistic TLS' => [false, 'smtp.example.test', 587, 'smtp', 'sender@example.test', 'user', 'secret'];
        yield 'external no credentials' => [false, 'smtp.example.test', 465, 'smtps', 'sender@example.test', null, null];
        yield 'external default sender' => [false, 'smtp.example.test', 465, 'smtps', 'noreply@holoul.test', 'user', 'secret'];
        yield 'external empty host' => [false, '', 465, 'smtps', 'sender@example.test', 'user', 'secret'];
    }

    #[DataProvider('insecureConfigurations')]
    public function test_invalid_configuration_is_rejected_before_selecting_any_transport(
        bool $sandbox, string $host, int $port, string $scheme, string $from, ?string $username, ?string $password,
    ): void {
        Config::set('identity.mail_sandbox', $sandbox);
        Config::set('mail.mailers.smtp', compact('host', 'port', 'scheme', 'username', 'password'));
        Config::set('mail.from.address', $from);
        $factory = $this->createMock(Factory::class);
        $factory->expects($this->never())->method('mailer');
        $this->expectException(MailDeliveryUncertain::class);
        (new SmtpMailTransport($factory))->send($this->message());
    }

    public function test_sandbox_adapter_drops_raw_smtp_exception_and_its_previous_exception(): void
    {
        Config::set('identity.mail_sandbox', true);
        Config::set('mail.mailers.smtp', ['host' => 'mailpit', 'port' => 1025, 'scheme' => 'smtp']);
        Config::set('mail.from.address', 'noreply@holoul.test');
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects($this->once())->method('raw')->willThrowException(new RuntimeException('Secret SMTP body and recipient@example.test'));
        $factory = $this->createMock(Factory::class);
        $factory->expects($this->once())->method('mailer')->with('smtp')->willReturn($mailer);
        try {
            (new SmtpMailTransport($factory))->send($this->message());
            $this->fail('SMTP failure was swallowed.');
        } catch (MailDeliveryUncertain $exception) {
            $this->assertSame('The mail delivery outcome is uncertain.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_templates_put_tokens_only_in_same_origin_spa_fragments(): void
    {
        Config::set('app.url', 'https://holoul.test');
        $id = (string) Str::uuid7();
        $token = str_repeat('a', 64);
        $message = app(RecoveryMailTemplates::class)->render($id, new RecoveryMailPayload('recipient@example.test', $token, RecoveryPurpose::PasswordReset));
        $this->assertStringContainsString('https://holoul.test/reset-password#token='.$token, $message->body);
        $this->assertStringNotContainsString('?token=', $message->body);
        $this->assertSame($id.'@holoul.test', $message->messageId);
        Config::set('app.url', 'https://attacker:password@holoul.test');
        $this->expectException(RuntimeException::class);
        app(RecoveryMailTemplates::class)->render($id, new RecoveryMailPayload('recipient@example.test', $token, RecoveryPurpose::PasswordReset));
    }

    private function message(): RecoveryMessage
    {
        return new RecoveryMessage((string) Str::uuid7(), 'recipient@example.test', 'Verify email', 'Secret body', 'recovery@holoul.test');
    }
}
