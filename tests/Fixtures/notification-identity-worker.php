<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\ReadActiveIdentity;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\SessionSecurity;
use App\Modules\Projects\Actions\ProjectStore;
use App\Modules\Projects\Data\ProjectActor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'holoul_test') {
    throw new LogicException('Dedicated PostgreSQL test database required.');
}
$input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
Queue::fake();
Config::set('identity.origin', 'https://localhost:8443');
Config::set('app.url', 'https://localhost:8443');
DB::select('SELECT set_config(?, ?, false)', ['application_name', $input['application']]);
DB::statement("SET lock_timeout='12s'");
DB::statement("SET statement_timeout='15s'");
$identityReads = 0;
DB::listen(function (QueryExecuted $query) use (&$identityReads): void {
    if (str_contains($query->sql, 'from "users"') && preg_match('/for (?:no key )?update/i', $query->sql) === 1) {
        $identityReads++;
    }
});
$status = 200;
if ($input['mode'] === 'http') {
    $kernel = $app->make(HttpKernel::class);
    $request = Request::create('https://localhost:8443/api/v1/projects/'.$input['project'], 'GET', [], $input['cookies'], [], [
        'HTTPS' => 'on', 'HTTP_HOST' => 'localhost:8443', 'SERVER_PORT' => '8443', 'REMOTE_ADDR' => '192.0.2.231',
        'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'https://localhost:8443',
    ]);
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $kernel->terminate($request, $response);
} elseif ($input['mode'] === 'durable') {
    DB::transaction(function () use ($input): void {
        $identity = app(ReadActiveIdentity::class)->locked($input['user']);
        $actor = new ProjectActor($identity->id, $input['customer'], $identity->verifiedEmail, false, $identity->permissions);
        app(ProjectStore::class)->find($actor, $input['project']);
    });
} else {
    DB::transaction(function () use ($input): void {
        if ($input['mode'] !== 'session_revoke') {
            app(RoleAuthority::class)->lockChanges();
            $user = User::query()->whereKey($input['user'])->lockForUpdate()->firstOrFail();
            if ($input['mode'] === 'disable') {
                $user->forceFill(['enabled' => false])->save();
            } elseif ($input['mode'] === 'role_revoke') {
                DB::table('user_roles')->where('user_id', $user->id)->delete();
            } else {
                throw new LogicException('Invalid mutation fixture.');
            }
        }
        app(SessionSecurity::class)->revokeAll($input['user'], (string) Str::uuid7());
    });
}
echo json_encode(['backend' => DB::scalar('SELECT pg_backend_pid()'), 'status' => $status, 'identity_reads' => $identityReads], JSON_THROW_ON_ERROR);
