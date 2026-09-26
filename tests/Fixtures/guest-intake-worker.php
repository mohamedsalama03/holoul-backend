<?php

declare(strict_types=1);

use App\Modules\Customers\Contracts\CustomerContactReader;
use App\Modules\Documents\Actions\ClaimGuestDocuments;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\StoredObject;
use App\Modules\ProjectIntake\Actions\ClaimGuestRequest;
use App\Modules\ProjectIntake\Actions\GuestDocuments;
use App\Modules\ProjectIntake\Actions\GuestDrafts;
use App\Modules\ProjectIntake\Actions\SubmitGuestRequest;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Actions\TransitionRequest;
use App\Modules\ProjectIntake\Data\IntakeActor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'holoul_test') {
    throw new LogicException('Isolated test database required.');
}
$input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
DB::select('SELECT set_config(?, ?, false)', ['application_name', $input['application']]);
DB::statement("SET lock_timeout = '12s'");
DB::statement("SET statement_timeout = '15s'");
$result = ['action' => $input['action'], 'backend' => DB::scalar('SELECT pg_backend_pid()')];
$correlation = (string) Str::uuid7();
try {
    $receipt = DB::transaction(function () use ($input, $correlation) {
        $action = $input['action'];
        if ($action === 'submit') {
            $receipt = app(SubmitGuestRequest::class)->handle($input['id'], $input['capability'], $input['session'], $input['etag'], $input['key'], $input['input'], $correlation);

            return ['reference' => $receipt['reference']];
        }
        if ($action === 'finalize') {
            [$record] = app(GuestDrafts::class)->lock($input['id'], $input['capability'], $input['session']);
            $attachments = app(GuestDocuments::class);
            $reservation = $attachments->upload($record, $input['document_id'], $input['etag'], $correlation);
            app(DocumentService::class)->finalize($attachments->owner($record, $correlation), $input['document_id'],
                new StoredObject($reservation->key, 'g1-race-version', $reservation->bytes, $reservation->sha256, $reservation->format->mime()));

            return [];
        }
        // Mirror the outer authenticated workflow's persisted identity/customer locks.
        DB::table('users')->where('id', $input['user_id'])->lock('for no key update')->first();
        $contact = app(CustomerContactReader::class)->currentForIdentity($input['user_id'], true);
        if ($contact === null) {
            throw new LogicException('Missing fixture customer.');
        }
        if ($action === 'profile') {
            DB::table('users')->where('id', $input['user_id'])->update(['full_name' => 'New coherent name']);
            DB::table('customers')->where('id', $contact->customerId)->update(['phone_e164' => '+12025550199', 'phone_display' => '+12025550199']);

            return [];
        }
        if ($action === 'claim') {
            $receipt = app(ClaimGuestRequest::class)->handle($contact, $input['token'], $input['key'], $correlation);
            app(ClaimGuestDocuments::class)->handle($receipt['request_id'], $contact->customerId, $contact->userId, $correlation);

            return $receipt;
        }
        $actor = new IntakeActor($contact->userId, $contact->customerId, $contact->verifiedEmail);
        if ($action === 'authenticated_submit') {
            app(SubmitRequest::class)->handle($actor, $input['id'], $input['etag'], $input['key'], $correlation);

            return [];
        }
        if ($action === 'withdraw') {
            app(TransitionRequest::class)->handle($actor, $input['id'], $input['etag'], 'withdraw', null, $correlation);

            return [];
        }
        throw new LogicException('Unknown fixture operation.');
    });
    $result += ['status' => 200, ...$receipt];
} catch (HttpExceptionInterface $failure) {
    $result += ['status' => $failure->getStatusCode()];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
