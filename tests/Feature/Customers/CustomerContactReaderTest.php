<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Actions\CreateCustomer;
use App\Modules\Customers\ReadModels\DatabaseCustomerContactReader;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class CustomerContactReaderTest extends TestCase
{
    use DatabaseMigrations;

    private static ?string $passwordHash = null;

    public function test_contact_contract_returns_only_the_requested_customer_and_snapshot_fields(): void
    {
        $first = $this->user();
        $second = $this->user();
        $profileA = app(CreateCustomer::class)->handle($first->id, '+218912345678', '+218 91 234 5678');
        $profileB = app(CreateCustomer::class)->handle($second->id, '+218923456789', '+218 92 345 6789');
        $reader = app(DatabaseCustomerContactReader::class);
        $contactA = $reader->currentForIdentity($first->id);
        $contactB = $reader->currentForIdentity($second->id);
        self::assertNotNull($contactA);
        self::assertNotNull($contactB);
        self::assertSame($profileA->id, $contactA->customerId);
        self::assertSame($profileB->id, $contactB->customerId);
        self::assertSame($first->id, $contactA->userId);
        self::assertSame($first->full_name, $contactA->fullName);
        self::assertSame($first->email, $contactA->email);
        self::assertSame('+218912345678', $contactA->phoneE164);
        self::assertFalse($contactA->verifiedEmail);
        self::assertSame(['customerId', 'userId', 'fullName', 'email', 'phoneE164', 'verifiedEmail'], array_keys(get_object_vars($contactA)));
        self::assertNotSame($contactA->userId, $contactB->userId);
    }

    public function test_disabled_staff_missing_profile_and_malformed_identity_are_not_contacts(): void
    {
        $withoutProfile = $this->user();
        $staff = $this->user('staff');
        $disabled = $this->user();
        app(CreateCustomer::class)->handle($disabled->id, '+218912345678', '+218 91 234 5678');
        $disabled->enabled = false;
        $disabled->save();
        $reader = app(DatabaseCustomerContactReader::class);
        foreach ([$withoutProfile->id, $staff->id, $disabled->id, (string) Str::uuid7(), 'malformed'] as $id) {
            self::assertNull($reader->currentForIdentity($id));
        }
    }

    public function test_snapshot_locks_identity_and_profile_then_remains_immutable_after_contact_changes(): void
    {
        $user = $this->user();
        $user->email_verified_at = now()->toImmutable();
        $user->save();
        $profile = app(CreateCustomer::class)->handle($user->id, '+218912345678', '+218 91 234 5678');
        Config::set('database.connections.contact_peer', Config::array('database.connections.'.DB::getDefaultConnection()));
        $peer = DB::connection('contact_peer');
        $peer->statement("SET lock_timeout = '100ms'");
        try {
            $snapshot = DB::transaction(function () use ($user, $profile, $peer) {
                $snapshot = app(DatabaseCustomerContactReader::class)->currentForIdentity($user->id, true);
                self::assertNotNull($snapshot);
                self::assertTrue($snapshot->verifiedEmail);
                foreach ([
                    fn () => $peer->table('users')->where('id', $user->id)->update(['full_name' => 'New Contact Name']),
                    fn () => $peer->table('customers')->where('id', $profile->id)->update(['phone_e164' => '+218923456789']),
                ] as $mutation) {
                    try {
                        $mutation();
                        self::fail('A submitted contact snapshot did not retain its row locks.');
                    } catch (QueryException $exception) {
                        self::assertSame('55P03', $exception->getCode());
                    }
                }

                return $snapshot;
            });
            $peer->table('users')->where('id', $user->id)->update(['full_name' => 'New Contact Name', 'email' => 'updated@example.test', 'email_verified_at' => null]);
            $peer->table('customers')->where('id', $profile->id)->update(['phone_e164' => '+218923456789']);
        } finally {
            DB::purge('contact_peer');
        }
        self::assertSame('Contact Test', $snapshot->fullName);
        self::assertSame($user->email, $snapshot->email);
        self::assertSame('+218912345678', $snapshot->phoneE164);
        self::assertTrue($snapshot->verifiedEmail);
        $current = app(DatabaseCustomerContactReader::class)->currentForIdentity($user->id);
        self::assertNotNull($current);
        self::assertSame('New Contact Name', $current->fullName);
        self::assertSame('updated@example.test', $current->email);
        self::assertSame('+218923456789', $current->phoneE164);
        self::assertFalse($current->verifiedEmail);
    }

    public function test_locking_a_contact_without_an_outer_transaction_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        app(DatabaseCustomerContactReader::class)->currentForIdentity((string) Str::uuid7(), true);
    }

    private function user(string $kind = 'customer'): User
    {
        self::$passwordHash ??= Hash::make('Contact test password 2026!');
        $email = Str::uuid7().'@example.test';

        return User::query()->create(['full_name' => 'Contact Test', 'email' => $email, 'email_display' => $email,
            'password' => self::$passwordHash, 'kind' => $kind, 'enabled' => true, 'auth_version' => 1]);
    }
}
