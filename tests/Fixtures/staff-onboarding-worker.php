<?php

declare(strict_types=1);
use App\Modules\Identity\Authorization\Actions\ChangeStaffAuthorization;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\SessionSecurity;
use App\Modules\Identity\Staff\CreateStaff;
use App\Modules\Identity\Staff\InvitationActions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'holoul_test') {
    exit(2);
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
DB::statement("SET application_name='holoul-staff-onboarding-race'");
Queue::fake();
try {
    if ($input['mode'] === 'accept') {
        app(InvitationActions::class)->accept($input['token'], 'Concurrency-Password-72', (string) Str::uuid7());
    } else {
        $user = User::query()->findOrFail($input['actor']);
        $request = Request::create('/api/v1/identity/staff/invitations', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();
        $request->attributes->set('request_id', (string) Str::uuid7());
        app(SessionSecurity::class)->completeLogin($request, $user, true);
        if ($input['mode'] === 'create') {
            app(CreateStaff::class)->handle($request, 'race.staff', $input['email'], 'Race Staff', 'Concurrency-Password-72', [Role::Support], $input['key']);
        } elseif ($input['mode'] === 'issue') {
            app(InvitationActions::class)->issue($request, $input['email'], 'Race Invite', [Role::Support], $input['key']);
        } else {
            app(ChangeStaffAuthorization::class)->handle($request, $input['target'], [Role::from($input['role'])], true, 1);
        }
    }
    echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
} catch (Throwable $failure) {
    echo json_encode(['status' => $failure instanceof HttpExceptionInterface ? $failure->getStatusCode() : 500, 'type' => $failure::class], JSON_THROW_ON_ERROR);
}
