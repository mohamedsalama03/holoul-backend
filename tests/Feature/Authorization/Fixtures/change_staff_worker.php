<?php

declare(strict_types=1);

use App\Modules\Identity\Authorization\Actions\ChangeStaffAuthorization;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    DB::statement("SET application_name = 'holoul-b2-admin-race'");
    $user = User::query()->findOrFail($argv[1]);
    $request = Request::create('/api/v1/identity/staff/'.$user->id.'/authorization', 'PATCH');
    $request->setLaravelSession(app('session.store'));
    $request->attributes->set('request_id', (string) Str::uuid7());
    $request->session()->start();
    app(SessionSecurity::class)->completeLogin($request, $user, true);
    $request->setUserResolver(fn (): User => $user);
    $disable = ($argv[2] ?? '') === 'disable';
    app(ChangeStaffAuthorization::class)->handle($request, $user->id, [$disable ? Role::SuperAdmin : Role::Support], ! $disable);
    echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $status = match (true) {
        $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
        $exception instanceof AuthenticationException => 401,
        $exception instanceof AuthorizationException => 403,
        default => 500,
    };
    echo json_encode(['status' => $status, 'exception_type' => $exception::class], JSON_THROW_ON_ERROR);
}
