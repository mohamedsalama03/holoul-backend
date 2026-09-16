<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Actions\CreateCustomer;
use App\Modules\Customers\Actions\ListCustomers;
use App\Modules\Customers\Actions\UpdateCustomer;
use App\Modules\Customers\Authorization\CustomerOwnership;
use App\Modules\Customers\Data\CustomerProfile;
use App\Modules\Customers\Http\CustomerInput;
use App\Modules\Customers\Policies\CustomerPolicy;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class CustomerIsolationTest extends TestCase
{
    use DatabaseMigrations;

    private static ?string $passwordHash = null;

    public function test_profile_ownership_is_server_derived_and_only_the_owner_is_listed_or_searched(): void
    {
        [$a, $profileA] = $this->customer('+12025550123');
        [$b, $profileB] = $this->customer('+442079460018');
        $list = app(ListCustomers::class);

        self::assertSame($a->id, $profileA->userId);
        self::assertTrue(Str::isUuid($profileA->id, version: 7));
        self::assertSame([$profileA->id], array_map(fn (CustomerProfile $profile): string => $profile->id, $list->handle($a->id)));
        self::assertSame([], $list->handle($a->id, $profileB->phoneE164));
        self::assertSame([$profileB->id], array_map(fn (CustomerProfile $profile): string => $profile->id, $list->handle($b->id, '207946')));
        self::assertSame([], $list->handle($a->id, '%'));
        self::assertSame([], $list->handle($a->id, '_'));
    }

    public function test_foreign_direct_and_nested_records_are_not_found_before_mutation(): void
    {
        [$a, $profileA] = $this->customer('+12025550123');
        [$b, $profileB] = $this->customer('+442079460018');
        $ownership = app(CustomerOwnership::class);

        foreach ([[$a->id, $profileB->id, null], [$a->id, $profileA->id, $b->id], [$a->id, $profileB->id, $a->id]] as [$actor, $customer, $parent]) {
            try {
                $ownership->find($actor, $customer, $parent);
                self::fail('A foreign customer was visible.');
            } catch (NotFoundHttpException $exception) {
                self::assertSame(404, $exception->getStatusCode());
            }

            try {
                app(UpdateCustomer::class)->handle($actor, $customer, '+33142345678', (string) Str::uuid7(), $parent);
                self::fail('A foreign customer was updated.');
            } catch (NotFoundHttpException $exception) {
                self::assertSame(404, $exception->getStatusCode());
            }
        }

        $this->assertDatabaseHas('customers', ['id' => $profileB->id, 'phone_e164' => '+442079460018', 'user_id' => $b->id]);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_owned_profile_update_is_audited_without_contact_content(): void
    {
        [$owner, $profile] = $this->customer('+12025550123');
        $requestId = (string) Str::uuid7();
        $updated = app(UpdateCustomer::class)->handle($owner->id, $profile->id, '+44 (20) 7946 0018', $requestId, $owner->id);

        self::assertSame('+442079460018', $updated->phoneE164);
        self::assertSame($owner->id, $updated->userId);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'customer.profile_updated', 'actor_id' => $owner->id, 'subject_id' => $profile->id, 'request_id' => $requestId, 'metadata' => '{}']);
    }

    #[DataProvider('malformedIds')]
    public function test_malformed_direct_and_parent_identifiers_return_not_found(string $id): void
    {
        [$owner, $profile] = $this->customer('+12025550123');

        foreach ([[$id, null], [$profile->id, $id]] as [$customer, $parent]) {
            try {
                app(CustomerOwnership::class)->find($owner->id, $customer, $parent);
                self::fail('A malformed identifier reached a record.');
            } catch (NotFoundHttpException $exception) {
                self::assertSame(404, $exception->getStatusCode());
            }
        }
    }

    #[DataProvider('forgedFields')]
    public function test_update_rejects_every_client_ownership_or_authority_field(string $field): void
    {
        $request = Request::create('/customers/example', 'PATCH', ['phone' => '+12025550123', $field => (string) Str::uuid7()]);
        $this->expectException(ValidationException::class);
        CustomerInput::phone($request);
    }

    public function test_policy_rejects_a_visible_record_when_the_account_is_disabled(): void
    {
        [$owner, $profile] = $this->customer('+12025550123');
        $record = app(CustomerOwnership::class)->find($owner->id, $profile->id);
        self::assertTrue(app(CustomerPolicy::class)->update($owner->id, $record));
        $owner->enabled = false;
        $owner->save();
        self::assertFalse(app(CustomerPolicy::class)->update($owner->id, $record));
    }

    public function test_staff_cannot_create_a_customer_persona_through_the_public_action(): void
    {
        $staff = $this->user('staff');
        $this->expectException(NotFoundHttpException::class);
        app(CreateCustomer::class)->handle($staff->id, '+12025550123', '+12025550123');
    }

    public function test_database_prevents_customer_owner_reassignment(): void
    {
        [$a, $profile] = $this->customer('+12025550123');
        $b = $this->user('customer');
        $this->expectException(QueryException::class);
        DB::table('customers')->where('id', $profile->id)->update(['user_id' => $b->id]);
    }

    public function test_database_rejects_staff_owners(): void
    {
        $staff = $this->user('staff');
        $this->expectException(QueryException::class);
        DB::table('customers')->insert($this->rawProfile($staff->id));
    }

    public function test_database_permits_only_one_profile_per_login(): void
    {
        [$owner] = $this->customer('+12025550123');
        $this->expectException(QueryException::class);
        DB::table('customers')->insert($this->rawProfile($owner->id));
    }

    public function test_database_rejects_invalid_e164_values(): void
    {
        $owner = $this->user('customer');
        $this->expectException(QueryException::class);
        DB::table('customers')->insert([...$this->rawProfile($owner->id), 'phone_e164' => '2025550123']);
    }

    /** @return array{User,CustomerProfile} */
    private function customer(string $phone): array
    {
        $user = $this->user('customer');

        return [$user, app(CreateCustomer::class)->handle($user->id, $phone, $phone)];
    }

    private function user(string $kind): User
    {
        self::$passwordHash ??= Hash::make('LongTestPassword42!');
        $email = Str::uuid7().'@example.test';

        return User::query()->create(['full_name' => 'Test Person', 'email' => $email, 'email_display' => $email, 'password' => self::$passwordHash, 'kind' => $kind, 'enabled' => true, 'auth_version' => 1]);
    }

    /** @return array<string,string> */
    private function rawProfile(string $userId): array
    {
        return ['id' => (string) Str::uuid7(), 'user_id' => $userId, 'customer_kind' => 'customer', 'phone_e164' => '+12025550123', 'phone_display' => '+12025550123', 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()];
    }

    /** @return array<string,array{string}> */
    public static function malformedIds(): array
    {
        return ['empty' => [''], 'numeric' => ['12'], 'text' => ['not-a-uuid'], 'path' => ['../private'], 'sql' => ["' OR 1=1 --"]];
    }

    /** @return array<string,array{string}> */
    public static function forgedFields(): array
    {
        $cases = [];

        foreach (['id', 'user_id', 'customer_id', 'customer_kind', 'kind', 'role', 'roles', 'permission', 'permissions', 'enabled', 'staff', 'email_verified_at'] as $field) {
            $cases[$field] = [$field];
        }

        return $cases;
    }
}
