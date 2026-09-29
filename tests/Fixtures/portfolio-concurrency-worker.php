<?php

declare(strict_types=1);

use App\Modules\PublicPortfolio\Actions\ManagePortfolio;
use App\Modules\PublicPortfolio\Data\PortfolioActor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Queue::fake();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
DB::select("SELECT set_config('application_name',?,false)", [$input['name']]);
$pid = DB::scalar('SELECT pg_backend_pid()');
try {
    $result = DB::transaction(fn () => app(ManagePortfolio::class)->publication(
        new PortfolioActor($input['actor'], ['portfolio.publish'], true), $input['id'], true, $input['etag'], $input['key'], (string) Str::uuid7()));
    echo json_encode(['pid' => $pid, 'status' => 201, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (HttpException $error) {
    echo json_encode(['pid' => $pid, 'status' => $error->getStatusCode()]);
}
