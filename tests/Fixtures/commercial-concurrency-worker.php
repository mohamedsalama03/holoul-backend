<?php

declare(strict_types=1);

use App\Application\Commercial\CommercialWorkflow;
use App\Application\Commercial\ExpireProposals;
use App\Application\Commercial\WithdrawCommercialRequest;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Data\IntakeActor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new LogicException('Test environment required.');
}
/** @var array{actor:string,request:string,proposal:string,action:string,etag:string,key:string,application:string} $input */
$input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
DB::select('SELECT set_config(?, ?, false)', ['application_name', $input['application']]);
DB::statement("SET lock_timeout='12s'");
DB::statement("SET statement_timeout='15s'");
$result = ['action' => $input['action'], 'backend' => DB::scalar('SELECT pg_backend_pid()')];
try {
    if ($input['action'] === 'expire') {
        $expired = app(ExpireProposals::class)->one($input['request'], $input['proposal']);
        $result += ['status' => $expired ? 200 : 409];
    } else {
        $user = User::query()->findOrFail($input['actor']);
        $authority = app(RoleAuthority::class);
        $actor = new IntakeActor($user->id, $user->kind === 'customer' ? DB::table('customers')->where('user_id', $user->id)->value('id') : null,
            $user->email_verified_at !== null, $authority->permissionsFor($authority->roles($user->id)));
        if ($input['action'] === 'request.withdraw') {
            DB::transaction(function () use ($actor, $input): void {
                $record = app(IntakeStore::class)->mutable($actor, $input['request'], $input['etag']);
                app(WithdrawCommercialRequest::class)->handle($actor, $record, true, 'Concurrent cancellation.', (string) Str::uuid7());
            });
        } else {
            app(CommercialWorkflow::class)->handle($actor, true, $input['request'], $input['proposal'], $input['action'], $input['etag'], $input['key'],
                in_array($input['action'], ['proposal.withdraw', 'proposal.supersede'], true) ? ['reason' => 'Concurrent closure.'] : [], (string) Str::uuid7());
        }
        $result += ['status' => 200];
    }
} catch (HttpExceptionInterface $e) {
    $result += ['status' => $e->getStatusCode()];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
