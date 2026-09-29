<?php

declare(strict_types=1);

use App\Modules\Contact\Actions\ReceiveContact;
use App\Modules\Contact\Data\ContactSubmission;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'holoul_test') {
    exit(2);
}
Queue::fake();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
DB::select("SELECT set_config('application_name',?,false)", [$input['name']]);
try {
    $receipt = app(ReceiveContact::class)->handle(new ContactSubmission('Concurrent Contact', 'concurrent@example.test', '+12025550123', null, $input['message']), $input['key'], (string) Str::uuid7());
    echo json_encode(['status' => 201, 'receipt' => $receipt, 'pid' => DB::scalar('SELECT pg_backend_pid()')], JSON_THROW_ON_ERROR);
} catch (HttpException $error) {
    echo json_encode(['status' => $error->getStatusCode(), 'pid' => DB::scalar('SELECT pg_backend_pid()')], JSON_THROW_ON_ERROR);
}
