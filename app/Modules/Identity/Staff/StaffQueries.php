<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Modules\Identity\Actions\CurrentCapabilities;
use App\Modules\Identity\Actions\OwnIdentity;
use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Authorization\StaffAccess;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class StaffQueries
{
    public function __construct(private StaffAccess $access, private RoleAuthority $authority) {}

    /** @return array<string,mixed> */
    public function capabilities(Request $request): array
    {
        return DB::transaction(function () use ($request): array {
            $this->authority->lockChanges();
            $actor = app(SessionSecurity::class)->authenticated($request);
            $identity = app(OwnIdentity::class)->read($request);
            $roles = app(StaffGrantPolicy::class)->assignable($actor);
            $principal = new AuthorizedIdentity($actor->id, $actor->kind,
                $actor->email_verified_at !== null, $this->authority->permissionsFor($this->authority->roles($actor->id)), $actor->username !== null);
            $capabilities = app(CurrentCapabilities::class);
            $recent = app(SessionSecurity::class)->hasRecentPassword($request);
            $current = $capabilities->forSession($principal, $recent);
            $potential = $capabilities->forSession($principal, true);
            if ($actor->kind === 'staff' && $this->authority->allows($actor, Permission::ReadStaff)) {
                $current[] = 'staff.view';
                $potential[] = 'staff.view';
            }
            if ($roles !== []) {
                $potential[] = 'staff.invitations.manage';
                if ($recent) {
                    $current[] = 'staff.invitations.manage';
                }
            } else {
                $current = array_values(array_diff($current, ['staff.authorization.manage']));
                $potential = array_values(array_diff($potential, ['staff.authorization.manage']));
            }
            $confirmable = array_values(array_diff($potential, $current));
            sort($current);
            sort($confirmable);

            return ['capabilities' => array_values(array_unique($current)), 'confirmable_capabilities' => $confirmable,
                'assignable_roles' => $roles, 'recent_password_confirmation' => $identity['recent_password_confirmation']];
        });
    }

    /** @return array<string,mixed> */
    public function directory(Request $request): array
    {
        return DB::transaction(function () use ($request): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->access->requirePermission($request, Permission::ReadStaff);
            StaffInput::only($request, ['q', 'role', 'enabled', 'cursor', 'limit']);
            Validator::make($request->all(), ['role' => ['sometimes', 'string', Rule::in(array_values(array_diff(array_column(Role::cases(), 'value'), ['customer'])))],
                'enabled' => ['sometimes', Rule::in(['true', 'false', '1', '0'])]])->validate();
            $query = DB::table('users')->where('kind', 'staff');
            if ($request->has('role')) {
                $query->whereExists(function (Builder $q) use ($request): void {
                    $q->selectRaw('1')->from('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
                        ->whereColumn('user_roles.user_id', 'users.id')->where('roles.code', $request->string('role')->toString());
                });
            }
            if ($request->has('enabled')) {
                $query->where('enabled', $request->boolean('enabled'));
            }
            [$rows,$meta] = $this->page($request, $query, 'staff');
            $ids = array_map(static fn (\stdClass $row): string => StaffData::text($row->id), $rows);
            $roles = $this->roleMap('user_roles', 'user_id', $ids);
            $enrolled = DB::table('identity_mfa')->whereIn('user_id', $ids)->whereNotNull('confirmed_at')->pluck('user_id')->all();
            $pending = DB::table('identity_staff_invitations')->whereIn('accepted_user_id', $ids)->whereNull('activated_at')->pluck('accepted_user_id')->all();
            $data = [];
            foreach ($rows as $row) {
                $data[] = ['id' => $row->id, 'full_name' => $row->full_name, 'email' => $row->email, 'enabled' => $row->enabled,
                    'roles' => $roles[StaffData::text($row->id)] ?? [], 'created_at' => StaffData::date($row->created_at),
                    'mfa_enrolled' => in_array($row->id, $enrolled, true), 'onboarding_pending' => in_array($row->id, $pending, true)
                        || ($row->username !== null && ! in_array($row->id, $enrolled, true))];
            }

            return ['data' => $data, 'meta' => $meta];
        }, 2);
    }

    /** @return array<string,mixed> */
    public function invitations(Request $request): array
    {
        return DB::transaction(function () use ($request): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->access->requirePermission($request, Permission::ReadStaff);
            StaffInput::only($request, ['q', 'status', 'cursor', 'limit']);
            Validator::make($request->all(), ['status' => ['sometimes', Rule::in(['pending', 'accepted', 'expired', 'revoked'])]])->validate();
            $query = $this->invitationBase();
            if ($request->has('status')) {
                $query->whereRaw("(CASE WHEN status='pending' AND expires_at <= CURRENT_TIMESTAMP THEN 'expired' ELSE status END) = ?", [$request->string('status')->toString()]);
            }
            [$rows,$meta] = $this->page($request, $query, 'invitations');
            $roles = $this->roleMap('identity_staff_invitation_roles', 'invitation_id', array_map(static fn (\stdClass $v): string => StaffData::text($v->id), $rows));
            $data = [];
            foreach ($rows as $row) {
                $data[] = $this->invitationRecord($row, $roles[StaffData::text($row->id)] ?? []);
            }

            return ['data' => $data, 'meta' => $meta];
        }, 2);
    }

    /** @return array<string,mixed> */
    public function invitation(StaffInvitation $invite): array
    {
        $row = $this->invitationBase()->where('id', $invite->id)->first();
        if ($row === null) {
            throw new \LogicException('Invitation missing.');
        }

        return $this->invitationRecord($row, array_map(static fn (Role $r): string => $r->value, $invite->offeredRoles()));
    }

    /** @param list<string> $roles
     * @return array<string,mixed>
     */
    private function invitationRecord(\stdClass $r, array $roles): array
    {
        return ['id' => $r->id, 'email' => $r->email, 'full_name' => $r->full_name, 'roles' => $roles,
            'status' => $r->effective_status,
            'invited_by' => ['id' => $r->invited_by, 'full_name' => $r->inviter_name], 'created_at' => StaffData::date($r->created_at),
            'expires_at' => StaffData::date($r->expires_at), 'last_sent_at' => StaffData::date($r->last_sent_at), 'send_count' => $r->send_count,
            'accepted_at' => StaffData::date($r->accepted_at), 'accepted_user_id' => $r->accepted_user_id, 'activated_at' => StaffData::date($r->activated_at),
            'revoked_at' => StaffData::date($r->revoked_at), 'delivery_status' => $r->delivery_status, 'etag' => '"'.(is_int($r->lock_version) ? $r->lock_version : throw new \LogicException('Invalid version.')).'"'];
    }

    private function invitationBase(): Builder
    {
        return DB::table('identity_staff_invitations')->select('identity_staff_invitations.*')
            ->selectRaw("CASE WHEN status='pending' AND expires_at <= CURRENT_TIMESTAMP THEN 'expired' ELSE status END AS effective_status")->selectSub(
                DB::table('identity_staff_invitation_mail')->select('state')
                    ->whereColumn('invitation_id', 'identity_staff_invitations.id')
                    ->whereColumn('generation', 'identity_staff_invitations.generation')->limit(1), 'delivery_status');
    }

    /** @return array{list<\stdClass>,array{next_cursor:?string,limit:int,total:int}} */
    private function page(Request $request, Builder $query, string $kind): array
    {
        if (is_string($request->input('q'))) {
            $request->merge(['q' => trim($request->string('q')->toString())]);
        }
        Validator::make($request->all(), ['q' => ['sometimes', 'string', 'min:2', 'max:100', 'not_regex:/[\p{C}]/u'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'string', 'max:2048']])->validate();
        $q = trim($request->string('q')->toString());
        if ($q !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $query->where(function (Builder $b) use ($pattern): void {
                $b->whereRaw('full_name ILIKE ?', [$pattern])->orWhereRaw('email ILIKE ?', [$pattern]);
            });
        }
        $scope = hash('sha256', json_encode([$kind, $q, $request->input('role'), $request->input('enabled'), $request->input('status')], JSON_THROW_ON_ERROR));
        $total = (clone $query)->count();
        $cursor = $request->input('cursor');
        if (is_string($cursor)) {
            try {
                $decoded = json_decode(Crypt::decryptString($cursor), true, 4, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = null;
            }
            if (! is_array($decoded) || ($decoded['scope'] ?? null) !== $scope || ! is_string($decoded['id'] ?? null) || ! Str::isUuid($decoded['id'])) {
                throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
            }
            $query->where('id', '>', $decoded['id']);
        }
        $limit = $request->integer('limit', 25);
        $rows = $query->orderBy('id')->limit($limit + 1)->get();
        $page = array_values($rows->take($limit)->values()->all());
        $last = $page[count($page) - 1] ?? null;
        $next = $rows->count() > $limit && $last !== null ? Crypt::encryptString(json_encode(['scope' => $scope, 'id' => $last->id], JSON_THROW_ON_ERROR)) : null;

        return [$page, ['next_cursor' => $next, 'limit' => $limit, 'total' => $total]];
    }

    /** @param list<string> $ids
     * @return array<string,list<string>>
     */
    private function roleMap(string $table, string $foreign, array $ids): array
    {
        $result = [];
        foreach (DB::table($table)->join('roles', 'roles.id', '=', $table.'.role_id')->whereIn($foreign, $ids)->orderBy('roles.code')->get([$foreign, 'roles.code']) as $row) {
            $result[StaffData::text($row->{$foreign})][] = StaffData::text($row->code);
        }

        return $result;
    }
}
