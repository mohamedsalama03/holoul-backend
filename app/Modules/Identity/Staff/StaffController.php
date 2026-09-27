<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Modules\Identity\Authorization\Actions\ChangeStaffAuthorization;
use App\Modules\Identity\Authorization\Actions\ReadStaffIdentity;
use App\Modules\Identity\Authorization\Http\StaffAuthorizationInput;
use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Authorization\StaffAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\AuthLimiter;
use App\Modules\Identity\Security\IdentityInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class StaffController
{
    public function directory(Request $request, StaffQueries $queries): JsonResponse
    {
        $this->start($request);

        return $this->json($queries->directory($request));
    }

    public function capabilities(Request $request, StaffQueries $queries): JsonResponse
    {
        $this->start($request);

        return $this->json(['data' => $queries->capabilities($request)]);
    }

    public function invitations(Request $request, StaffQueries $queries): JsonResponse
    {
        $this->start($request);

        return $this->json($queries->invitations($request));
    }

    public function authorization(Request $request, string $user): JsonResponse
    {
        $this->start($request);
        StaffInput::only($request, []);

        return DB::transaction(function () use ($request, $user): JsonResponse {
            app(RoleAuthority::class)->lockChanges();
            $record = app(ReadStaffIdentity::class)->handle($request, $user);
            $version = User::query()->findOrFail($user)->authorization_revision;

            return $this->json(['data' => ['id' => $record->id, 'enabled' => $record->enabled, 'roles' => $record->roles]], etag: '"'.$version.'"');
        });
    }

    public function replaceAuthorization(Request $request, string $user): JsonResponse
    {
        $this->start($request);
        app(ReadStaffIdentity::class)->handle($request, $user);
        $actor = app(StaffAccess::class)->requirePermission($request, Permission::ManageStaff);
        StaffInput::recent($request);
        $roles = StaffAuthorizationInput::roles($request);
        $version = StaffInput::version($request);

        return DB::transaction(function () use ($request, $user, $actor, $roles, $version): JsonResponse {
            app(RoleAuthority::class)->lockChanges();
            $record = app(ChangeStaffAuthorization::class)->handle($request, $user, $roles, $request->boolean('enabled'), $version);
            $newVersion = User::query()->findOrFail($user)->authorization_revision;
            $changed = $newVersion !== $version;

            return $this->json(['data' => ['id' => $record->id, 'enabled' => $record->enabled, 'roles' => $record->roles],
                'meta' => ['changed' => $changed, 'current_session_revoked' => $changed && $actor->id === $record->id]]);
        });
    }

    public function issue(Request $request, InvitationActions $actions, StaffQueries $queries): JsonResponse
    {
        $this->start($request);
        $actor = app(StaffAccess::class)->requirePermission($request, Permission::ManageStaff);
        app(AuthLimiter::class)->consume([['key' => 'staff.invite:'.$actor->id, 'maximum' => 20, 'seconds' => 3600]]);
        StaffInput::only($request, ['email', 'full_name', 'roles']);
        Validator::make($request->all(), ['email' => ['required', 'string'], 'full_name' => ['required', 'string']])->validate();
        $request->merge(['email' => IdentityInput::email($request->string('email')->toString()), 'full_name' => IdentityInput::name($request->string('full_name')->toString())]);
        Validator::make($request->all(), ['full_name' => ['required', 'string', 'min:2', 'max:160', 'not_regex:/[\p{C}]/u'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'regex:/\A[\x21-\x7e]+\z/']])->validate();
        $invite = $actions->issue($request, $request->string('email')->toString(), $request->string('full_name')->toString(), StaffInput::roles($request), $request->header('Idempotency-Key', ''));
        $data = $queries->invitation($invite);

        return $this->json(['data' => $data], 201, StaffData::text($data['etag']));
    }

    public function resend(Request $request, string $invitation, InvitationActions $actions, StaffQueries $queries): JsonResponse
    {
        return $this->change($request, $invitation, $actions, $queries, true);
    }

    public function revoke(Request $request, string $invitation, InvitationActions $actions, StaffQueries $queries): JsonResponse
    {
        return $this->change($request, $invitation, $actions, $queries, false);
    }

    public function lookup(Request $request, InvitationActions $actions): JsonResponse
    {
        $this->publicStart($request);
        StaffInput::only($request, ['token']);

        return $this->json(['data' => $actions->lookup(StaffInput::token($request))]);
    }

    public function accept(Request $request, InvitationActions $actions): JsonResponse
    {
        $this->publicStart($request);
        StaffInput::only($request, ['token', 'password', 'password_confirmation']);
        $token = StaffInput::token($request);
        // Invalid tokens have the same response independent of password contents.
        $actions->lookup($token);
        Validator::make($request->all(), ['password' => [...IdentityInput::passwordRules(), 'confirmed']])->validate();
        $actions->accept($token, $request->string('password')->toString(), IdentityInput::requestId($request));

        return $this->json(['data' => ['next_step' => 'sign_in']]);
    }

    private function start(Request $request): void
    {
        $request->attributes->set('staff_contract', true);
    }

    private function publicStart(Request $request): void
    {
        $this->start($request);
        app(AuthLimiter::class)->consume([['key' => 'staff.invitation.public:'.($request->ip() ?? 'unknown'), 'maximum' => 30, 'seconds' => 60],
            ['key' => 'staff.invitation.public.hour:'.($request->ip() ?? 'unknown'), 'maximum' => 120, 'seconds' => 3600]]);
        // Never change a logged-in or pending-MFA browser's identity. Even stale
        // session markers are refused without invalidating or replacing cookies.
        if ($request->hasSession() && ($request->session()->has('identity.session_id') || $request->session()->has('identity.pending_user_id'))) {
            throw new StaffFailure(409, 'INVITATION_SIGN_OUT_REQUIRED');
        }
    }

    private function change(Request $request, string $id, InvitationActions $actions, StaffQueries $queries, bool $resend): JsonResponse
    {
        $this->start($request);
        StaffInput::only($request, []);
        $actor = app(StaffAccess::class)->requirePermission($request, Permission::ManageStaff);
        app(AuthLimiter::class)->consume([['key' => 'staff.invitation.change:'.$actor->id, 'maximum' => 30, 'seconds' => 60]]);
        $invite = $actions->change($request, $id, StaffInput::version($request), $resend);
        $data = $queries->invitation($invite);

        return $this->json(['data' => $data], etag: StaffData::text($data['etag']));
    }

    /** @param array<string,mixed> $body */
    private function json(array $body, int $status = 200, ?string $etag = null): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        if ($etag !== null) {
            $headers['ETag'] = $etag;
        }

        return new JsonResponse($body, $status, $headers);
    }
}
