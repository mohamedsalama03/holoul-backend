<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Contracts\IdentityContact;
use PHPUnit\Framework\TestCase;

final class StaffEmailPrerequisiteTest extends TestCase
{
    public function test_account_admission_never_fabricates_email_ownership_or_staff_authority(): void
    {
        foreach (['staff', 'customer'] as $kind) {
            foreach ([false, true] as $verified) {
                foreach ([false, true] as $direct) {
                    $expected = $verified || $kind === 'customer' || ($kind === 'staff' && $direct);
                    $identity = new AuthorizedIdentity('id', $kind, $verified, [], $direct);
                    $contact = new IdentityContact('id', $kind, true, 'Name', 'synthetic@example.test', $verified, $direct);
                    self::assertSame($verified, $identity->verifiedEmail);
                    self::assertSame($verified, $contact->verifiedEmail);
                    self::assertSame($expected, $identity->emailPrerequisiteSatisfied);
                    self::assertSame($expected, $contact->emailPrerequisiteSatisfied);
                    self::assertFalse($identity->allows('reporting.read'));
                }
            }
        }
        self::assertFalse((new AuthorizedIdentity('id', 'staff', false, []))->emailPrerequisiteSatisfied);
        self::assertFalse((new IdentityContact('id', 'staff', true, 'Name', 'synthetic@example.test', false))->emailPrerequisiteSatisfied);
    }
}
