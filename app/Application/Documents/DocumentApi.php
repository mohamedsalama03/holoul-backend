<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Customers\Contracts\CustomerContactReader;
use App\Modules\Documents\Actions\StoreUpload;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Data\DocumentDownload;
use App\Modules\Documents\Data\DocumentView;
use App\Modules\Documents\Data\UploadReservation;
use App\Modules\Documents\Exceptions\StorageConflict;
use App\Modules\Documents\Exceptions\StorageUnavailable;
use App\Modules\Identity\Actions\WithAuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Security\AuthLimiter;
use App\Modules\ProjectIntake\Actions\IntakeDocuments;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final readonly class DocumentApi
{
    public function __construct(private WithAuthorizedIdentity $identity, private CustomerContactReader $contacts,
        private IntakeStore $intake, private IntakeDocuments $attachments, private DocumentService $documents,
        private StoreUpload $uploads, private PrivateObjectStore $objects, private AuthLimiter $limiter) {}

    /** @param array<string,mixed> $input */
    public function handle(Request $request, string $operation, array $input): Response
    {
        try {
            return $this->perform($request, $operation, $input);
        } catch (StorageUnavailable) {
            throw new HttpException(503);
        } catch (StorageConflict) {
            throw new HttpException(409);
        }
    }

    /** @param array<string,mixed> $input */
    private function perform(Request $request, string $operation, array $input): Response
    {
        $requestId = $request->attributes->get('request_id');
        $requestId = is_string($requestId) ? $requestId : (string) Str::uuid7();
        if ($operation === 'content') {
            return $this->upload($request, $requestId);
        }
        if ($operation === 'download') {
            return $this->download($request, $requestId);
        }

        return $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $requestId, $operation, $input): JsonResponse {
            [$actor, $record] = $this->context($request, $identity);
            $owner = $this->attachments->owner($record, $actor, $requestId);
            $id = $this->parameter($request, 'document');
            $status = 200;
            if ($operation === 'reserve') {
                $this->attachments->editable($record, $actor);
                $this->charge($actor, 'reserve');
                $key = $request->header('Idempotency-Key');
                if (! is_string($key) || preg_match('/\A[A-Za-z0-9_.:-]{16,128}\z/D', $key) !== 1) {
                    throw ValidationException::withMessages(['idempotency_key' => 'A bounded unique key is required.']);
                }
                $filename = $input['filename'] ?? null;
                $bytes = $input['bytes'] ?? null;
                $sha256 = $input['sha256'] ?? null;
                if (! is_string($filename) || ! is_int($bytes) || ! is_string($sha256)) {
                    throw new HttpException(422);
                }
                $reservation = $this->attachments->reserve($record, $actor, $request->header('If-Match'), $filename, $bytes, $sha256, $key, $requestId);
                $view = $this->documents->metadata($owner, $reservation->documentId);
                $status = 201;
            } elseif ($operation === 'remove') {
                $view = $this->attachments->remove($record, $actor, $id, $request->header('If-Match'), $requestId);
            } elseif ($operation === 'retry') {
                $this->intake->policy->owner($actor, $record);
                if (! $actor->verifiedEmail) {
                    throw new AuthorizationException;
                }
                VersionPrecondition::require($request->header('If-Match'), $record->id, $record->lock_version);
                $this->attachments->readable($record, $actor, $id, false);
                $this->charge($actor, 'retry');
                $view = $this->documents->retryScan($owner, $id);
            } elseif ($operation === 'metadata') {
                $this->attachments->readable($record, $actor, $id, false);
                $view = $this->documents->metadata($owner, $id);
            } else {
                throw new HttpException(404);
            }

            return $this->response($view, $record, $status);
        });
    }

    private function upload(Request $request, string $requestId): JsonResponse
    {
        $id = $this->parameter($request, 'document');
        $reservation = $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $requestId, $id): UploadReservation {
            [$actor, $record] = $this->context($request, $identity);
            $reservation = $this->attachments->upload($record, $actor, $id, $request->header('If-Match'), $requestId);
            $this->charge($actor, 'content');

            return $reservation;
        });
        $stream = $request->getContent(true);
        if (! is_resource($stream)) {
            throw new HttpException(400);
        }
        try {
            $object = $this->uploads->handle($reservation, $stream);
        } finally {
            fclose($stream);
        }

        return $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $requestId, $id, $object): JsonResponse {
            [$actor, $record] = $this->context($request, $identity);
            $this->attachments->upload($record, $actor, $id, $request->header('If-Match'), $requestId);
            $view = $this->documents->finalize($this->attachments->owner($record, $actor, $requestId), $id, $object);

            return $this->response($view, $record);
        });
    }

    private function download(Request $request, string $requestId): StreamedResponse
    {
        $grant = $this->downloadGrant($request, $requestId);
        $stream = $this->objects->openVerified($grant->object);
        try {
            // Storage I/O has completed. Recheck persisted identity, parent scope,
            // attachment, availability and exact immutable version before any byte.
            $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $requestId, $grant): void {
                [$actor, $record] = $this->context($request, $identity);
                $this->attachments->readable($record, $actor, $grant->document->id, true);
                $this->documents->consumeDownload($this->attachments->owner($record, $actor, $requestId), $grant);
            });
        } catch (Throwable $failure) {
            fclose($stream);
            throw $failure;
        }
        $filename = $grant->document->filename;
        $fallback = 'document.'.$grant->document->format->value;

        return new StreamedResponse(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, ['Content-Type' => $grant->document->format->mime(), 'Content-Length' => (string) $grant->object->size,
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $filename, $fallback),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache',
            'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function downloadGrant(Request $request, string $requestId): DocumentDownload
    {
        return $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $requestId): DocumentDownload {
            [$actor, $record] = $this->context($request, $identity);
            $id = $this->parameter($request, 'document');
            $this->attachments->readable($record, $actor, $id, true);

            return $this->documents->authorizeDownload($this->attachments->owner($record, $actor, $requestId), $id);
        });
    }

    /** @return array{IntakeActor,ProjectRequest} */
    private function context(Request $request, AuthorizedIdentity $identity): array
    {
        $staffRoute = $request->is('api/v1/admin/*');
        if (($staffRoute && $identity->kind !== 'staff') || (! $staffRoute && $identity->kind !== 'customer')) {
            throw new AuthorizationException;
        }
        $contact = $identity->kind === 'customer' ? $this->contacts->currentForIdentity($identity->id) : null;
        if ($identity->kind === 'customer' && $contact === null) {
            throw new AuthorizationException;
        }
        $actor = new IntakeActor($identity->id, $contact?->customerId, $identity->verifiedEmail, $identity->permissions);

        return [$actor, $this->intake->find($actor, $this->parameter($request, 'projectRequest'), true)];
    }

    private function charge(IntakeActor $actor, string $purpose): void
    {
        $this->limiter->consume([['key' => 'documents:'.$purpose.':'.$actor->id, 'maximum' => 20, 'seconds' => 60]]);
    }

    private function response(DocumentView $view, ProjectRequest $record, int $status = 200): JsonResponse
    {
        $headers = ['ETag' => VersionPrecondition::etag($record->id, $record->lock_version), 'Cache-Control' => 'private, no-store'];
        if ($status === 201) {
            $headers['Location'] = '/api/v1/project-requests/'.$record->id.'/documents/'.$view->id;
        }

        return new JsonResponse(['data' => $view->toArray()], $status, $headers);
    }

    private function parameter(Request $request, string $name): string
    {
        $value = $request->route($name);
        if ($name === 'document' && is_string($value) && ! Str::isUuid($value, 7)) {
            throw new HttpException(404);
        }

        return is_string($value) ? $value : '';
    }
}
