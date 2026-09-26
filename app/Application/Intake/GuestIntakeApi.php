<?php

declare(strict_types=1);

namespace App\Application\Intake;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Categories\Contracts\TaxonomyReader;
use App\Modules\Customers\Contracts\CustomerContactReader;
use App\Modules\Documents\Actions\ClaimGuestDocuments;
use App\Modules\Documents\Actions\StoreUpload;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\UploadReservation;
use App\Modules\Documents\Exceptions\StorageConflict;
use App\Modules\Documents\Exceptions\StorageUnavailable;
use App\Modules\Identity\Actions\WithAuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Security\IdentityInput;
use App\Modules\ProjectIntake\Actions\ClaimGuestRequest;
use App\Modules\ProjectIntake\Actions\GuestDocuments;
use App\Modules\ProjectIntake\Actions\GuestDrafts;
use App\Modules\ProjectIntake\Actions\SubmitGuestRequest;
use App\Modules\ProjectIntake\Data\ClaimRejected;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class GuestIntakeApi
{
    public function __construct(private GuestDrafts $drafts, private SubmitGuestRequest $submissions,
        private GuestDocuments $attachments, private DocumentService $documents, private StoreUpload $uploads,
        private WithAuthorizedIdentity $identity, private CustomerContactReader $contacts, private ClaimGuestRequest $claims,
        private ClaimGuestDocuments $claimDocuments, private RecordAuditEvent $audit, private TaxonomyReader $taxonomy) {}

    /** @param array<string,mixed> $input */
    public function handle(Request $request, string $operation, array $input): JsonResponse
    {
        $correlation = IdentityInput::requestId($request);
        if ($operation === 'claim') {
            return $this->claim($request, $input, $correlation);
        }
        if (in_array($operation, ['categories', 'subcategories'], true)) {
            $limit = is_int($input['limit'] ?? null) ? $input['limit'] : 25;
            $cursor = is_string($input['cursor'] ?? null) ? $input['cursor'] : null;
            $page = $operation === 'categories' ? $this->taxonomy->categories($limit, $cursor)
                : $this->taxonomy->subcategories($this->parameter($request, 'category'), $limit, $cursor);

            return new JsonResponse(['data' => array_map(fn ($row): array => $row->toArray(), $page->items), 'meta' => ['next_cursor' => $page->nextCursor]]);
        }
        // Bind the capability to this anonymous CSRF generation as well as the cookie ID.
        // Explicit bootstrap of a retired cookie cannot revive a destroyed draft capability.
        $binding = hash('sha256', json_encode([$request->session()->getId(), $request->session()->token()], JSON_THROW_ON_ERROR));
        if ($operation === 'create') {
            $result = $this->drafts->create($binding, $correlation);

            return new JsonResponse(['data' => $result], 201, ['ETag' => $result['etag']]);
        }
        $id = $this->parameter($request, 'projectRequest');
        $capability = $request->header('X-Intake-Capability', '');
        if ($operation === 'submit') {
            return new JsonResponse(['data' => $this->submissions->handle($id, $capability, $binding, $request->header('If-Match'),
                $request->header('Idempotency-Key'), $input, $correlation)], 201);
        }
        try {
            if ($operation === 'content') {
                return $this->upload($request, $id, $capability, $binding, $correlation);
            }

            return DB::transaction(function () use ($request, $id, $capability, $binding, $operation, $input, $correlation): JsonResponse {
                [$record] = $this->drafts->lock($id, $capability, $binding);
                $documentId = $operation === 'reserve' ? '' : $this->parameter($request, 'document');
                if ($operation === 'reserve') {
                    SubmitGuestRequest::requireKey($request->header('Idempotency-Key'));
                    if (! is_string($input['filename'] ?? null) || ! is_int($input['bytes'] ?? null) || ! is_string($input['sha256'] ?? null)) {
                        throw new HttpException(422);
                    }
                    $reservation = $this->attachments->reserve($record, $request->header('If-Match'), $input['filename'], $input['bytes'],
                        $input['sha256'], $request->header('Idempotency-Key', ''), $correlation);
                    $documentId = $reservation->documentId;
                } elseif ($operation !== 'metadata') {
                    throw new HttpException(404);
                }
                $this->attachments->attached($record, $documentId);
                $view = $this->documents->metadata($this->attachments->owner($record, $correlation), $documentId);

                return new JsonResponse(['data' => $view->toArray()], $operation === 'reserve' ? 201 : 200,
                    ['ETag' => VersionPrecondition::etag($record->id, $record->lock_version)]);
            });
        } catch (StorageUnavailable) {
            throw new HttpException(503);
        } catch (StorageConflict) {
            throw new HttpException(409);
        }
    }

    private function upload(Request $request, string $id, string $capability, string $binding, string $correlation): JsonResponse
    {
        $documentId = $this->parameter($request, 'document');
        $reservation = DB::transaction(function () use ($request, $id, $capability, $binding, $correlation, $documentId): UploadReservation {
            [$record] = $this->drafts->lock($id, $capability, $binding);

            return $this->attachments->upload($record, $documentId, $request->header('If-Match'), $correlation);
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

        return DB::transaction(function () use ($request, $id, $capability, $binding, $correlation, $documentId, $object): JsonResponse {
            [$record] = $this->drafts->lock($id, $capability, $binding);
            $this->attachments->upload($record, $documentId, $request->header('If-Match'), $correlation);
            $view = $this->documents->finalize($this->attachments->owner($record, $correlation), $documentId, $object);

            return new JsonResponse(['data' => $view->toArray()], 200, ['ETag' => VersionPrecondition::etag($record->id, $record->lock_version)]);
        });
    }

    /** @param array<string,mixed> $input */
    private function claim(Request $request, array $input, string $correlation): JsonResponse
    {
        $this->audit->handle('intake.claim_attempted', 'intake.attempt', $correlation, $correlation);
        try {
            return $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $input, $correlation): JsonResponse {
                if ($identity->kind !== 'customer') {
                    throw new AuthorizationException;
                }
                $contact = $this->contacts->currentForIdentity($identity->id, true);
                if ($contact === null || ! is_string($input['token'] ?? null)) {
                    throw new ClaimRejected;
                }
                $receipt = $this->claims->handle($contact, $input['token'], $request->header('Idempotency-Key'), $correlation);
                $this->claimDocuments->handle($receipt['request_id'], $contact->customerId, $contact->userId, $correlation);

                return new JsonResponse(['data' => $receipt], 200, ['ETag' => VersionPrecondition::etag($receipt['request_id'], $receipt['version'])]);
            });
        } catch (ClaimRejected $failure) {
            // Outside the rolled-back business transaction: rejected attempts remain visible without tokens/contact.
            $this->audit->handle($failure->expired ? 'intake.claim_expired' : 'intake.claim_rejected', 'intake.attempt', $correlation, $correlation);
            throw $failure;
        }
    }

    private function parameter(Request $request, string $name): string
    {
        $value = $request->route($name);
        if (! is_string($value) || ! Str::isUuid($value, 7)) {
            throw new HttpException(404);
        }

        return $value;
    }
}
