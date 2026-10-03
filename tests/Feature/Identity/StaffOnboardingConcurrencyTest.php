<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\SessionSecurity;
use App\Modules\Identity\Staff\InvitationActions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\TestCase;

final class StaffOnboardingConcurrencyTest extends TestCase
{
    use CommercialDatabase;

    public function test_direct_create_replay_is_deduplicated_under_postgresql_contention(): void
    {
        $actor = $this->staff(Role::SuperAdmin);
        $input = ['mode' => 'create', 'actor' => $actor->id, 'email' => 'direct-race@example.test', 'key' => (string) Str::uuid7()];
        self::assertSame([200, 200], $this->race([$input, $input]));
        $this->assertDatabaseCount('identity_staff_creation_keys', 1);
        $this->assertDatabaseCount('identity_recovery_mail', 0);
        self::assertSame(1, User::query()->where('username', 'race.staff')->count());
    }

    public function test_competing_direct_creations_cannot_claim_the_same_username(): void
    {
        $actor = $this->staff(Role::SuperAdmin);
        $input = ['mode' => 'create', 'actor' => $actor->id, 'email' => 'direct-race@example.test', 'key' => (string) Str::uuid7()];
        self::assertSame([200, 409], $this->race([$input, [...$input, 'email' => 'another-race@example.test', 'key' => (string) Str::uuid7()]]));
        $this->assertDatabaseCount('identity_staff_creation_keys', 1);
        $this->assertDatabaseCount('identity_recovery_mail', 0);
    }

    public function test_duplicate_issue_records_one_invitation_and_one_durable_mail_intent(): void
    {
        $actor = $this->staff(Role::SuperAdmin);
        $email = 'race@example.test';
        $input = ['mode' => 'issue', 'actor' => $actor->id, 'email' => $email, 'key' => (string) Str::uuid7()];
        self::assertSame([200, 409], $this->race([$input, [...$input, 'key' => (string) Str::uuid7()]]));
        $this->assertDatabaseCount('identity_staff_invitations', 1);
        $this->assertDatabaseCount('identity_staff_invitation_mail', 1);
    }

    public function test_same_key_concurrent_replay_is_deduplicated(): void
    {
        $actor = $this->staff(Role::SuperAdmin);
        $input = ['mode' => 'issue', 'actor' => $actor->id, 'email' => 'retry@example.test', 'key' => (string) Str::uuid7()];
        self::assertSame([200, 200], $this->race([$input, $input]));
        $this->assertDatabaseCount('identity_staff_invitation_mail', 1);
    }

    public function test_only_one_concurrent_acceptance_creates_staff_credentials(): void
    {
        Queue::fake();
        $actor = $this->staff(Role::SuperAdmin);
        $request = Request::create('/', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();
        $request->attributes->set('request_id', (string) Str::uuid7());
        app(SessionSecurity::class)->completeLogin($request, $actor, true);
        $invite = app(InvitationActions::class)->issue($request, 'accept-race@example.test', 'Invite Race', [Role::Support], (string) Str::uuid7());
        $token = bin2hex(random_bytes(32));
        DB::table('identity_staff_invitations')->where('id', $invite->id)->update(['token_hash' => hash('sha256', $token)]);
        self::assertSame([200, 422], $this->race([['mode' => 'accept', 'token' => $token], ['mode' => 'accept', 'token' => $token]]));
        self::assertSame(1, User::query()->where('email', $invite->email)->count());
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'identity.staff_invitation.accepted')->count());
    }

    public function test_two_guarded_role_replacements_cannot_silently_overwrite(): void
    {
        $actor = $this->staff(Role::SuperAdmin);
        $target = $this->staff(Role::Support);
        $input = ['mode' => 'replace', 'actor' => $actor->id, 'target' => $target->id, 'role' => 'reviewer'];
        self::assertSame([200, 412], $this->race([$input, [...$input, 'role' => 'sales']]));
        self::assertSame(2, $target->refresh()->authorization_revision);
    }

    private function staff(Role $role): User
    {
        $email = Str::uuid7().'@example.test';
        $u = User::query()->create(['full_name' => 'Race Staff', 'email' => $email, 'email_display' => $email, 'password' => 'Concurrency-Password-72', 'kind' => 'staff', 'enabled' => true, 'auth_version' => 1]);
        DB::table('user_roles')->insert(['user_id' => $u->id, 'role_id' => app(RoleAuthority::class)->roleId($role), 'user_kind' => 'staff']);

        return $u;
    }

    private function race(array $inputs): array
    {
        $peers = [];
        DB::beginTransaction();
        try {
            app(RoleAuthority::class)->lockChanges();
            foreach ($inputs as $input) {
                $p = new Process([PHP_BINARY, base_path('tests/Fixtures/staff-onboarding-worker.php')], base_path(), timeout: 30);
                $p->setInput(json_encode($input, JSON_THROW_ON_ERROR));
                $p->start();
                $peers[] = $p;
            }
            $deadline = microtime(true) + 15;
            $waiting = 0;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'holoul-staff-onboarding-race')->where('wait_event_type', 'Lock')->count();
                if ($waiting === count($peers)) {
                    break;
                }usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(count($peers), $waiting, 'Independent requests must reach the PostgreSQL lock barrier.');
            DB::commit();
            $result = [];
            foreach ($peers as $p) {
                self::assertSame(0, $p->wait(), $p->getErrorOutput());
                $body = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $result[] = $body['status'];
            }
            sort($result);

            return $result;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }foreach ($peers as $p) {
                if ($p->isRunning()) {
                    $p->stop(1);
                }
            }
        }
    }
}
