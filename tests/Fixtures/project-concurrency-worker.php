<?php

declare(strict_types=1);

use App\Application\Commercial\CommercialWorkflow;
use App\Application\Commercial\WithdrawCommercialRequest;
use App\Application\Projects\ConvertRequest;
use App\Application\Projects\ProjectWorkflow;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\Projects\Data\ProjectActor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'holoul_test') {
    throw new LogicException('Dedicated PostgreSQL test database required.');
}
$input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
DB::select('SELECT set_config(?, ?, false)', ['application_name', $input['application']]);
DB::statement("SET lock_timeout='12s'");
DB::statement("SET statement_timeout='15s'");
$result = ['action' => $input['action'], 'backend' => DB::scalar('SELECT pg_backend_pid()')];
try {
    $user = User::query()->findOrFail($input['actor']);
    $authority = app(RoleAuthority::class);
    $customer = $user->kind === 'customer' ? DB::table('customers')->where('user_id', $user->id)->value('id') : null;
    $permissions = $authority->permissionsFor($authority->roles($user->id));
    $intake = new IntakeActor($user->id, $customer, $user->email_verified_at !== null, $permissions);
    $actor = new ProjectActor($user->id, $customer, $user->email_verified_at !== null, true, $permissions);
    $correlation = (string) Str::uuid7();
    if ($input['action'] === 'project.convert') {
        $result['data'] = app(ConvertRequest::class)->handle($actor, $input['request'], $input['etag'], $input['key'], $correlation);
    } elseif ($input['action'] === 'proposal.rescind') {
        $result['data'] = app(CommercialWorkflow::class)->handle($intake, true, $input['request'], $input['proposal'],
            'proposal.rescind', $input['etag'], $input['key'], ['reason' => 'Concurrent owner rescission.'], $correlation);
    } elseif ($input['action'] === 'request.withdraw') {
        DB::transaction(function () use ($intake, $input, $correlation): void {
            $request = app(IntakeStore::class)->mutable($intake, $input['request'], $input['etag']);
            app(WithdrawCommercialRequest::class)->handle($intake, $request, true, 'Concurrent request withdrawal.', $correlation);
        });
    } else {
        $result['data'] = app(ProjectWorkflow::class)->handle($actor, $input['project'], $input['id'] ?? '', $input['action'],
            $input['etag'], $input['key'], $input['input'] ?? [], $correlation);
    }
    $result['status'] = 200;
} catch (HttpExceptionInterface $error) {
    $result['status'] = $error->getStatusCode();
}
echo json_encode($result, JSON_THROW_ON_ERROR);
