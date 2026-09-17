<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Modules\Customers\Contracts\CustomerContactReader;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Data\DocumentDownload;
use App\Modules\Documents\Exceptions\StorageConflict;
use App\Modules\Documents\Exceptions\StorageUnavailable;
use App\Modules\Identity\Actions\WithAuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\ProjectIntake\Actions\CommercialIntake;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Contracts\CommercialContext;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\Proposals\Actions\ProposalDocuments;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final readonly class ProposalDocumentApi
{
    public function __construct(private WithAuthorizedIdentity $identity, private CustomerContactReader $contacts,
        private IntakeStore $intake, private CommercialIntake $contexts, private ProposalDocuments $attachments,
        private DocumentService $documents, private PrivateObjectStore $objects) {}

    public function handle(Request $request): Response
    {
        $download = str_ends_with($request->path(), '/download');
        $document = $this->parameter($request, 'document');
        if (! $download) {
            return $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $document): JsonResponse {
                $context = $this->context($request, $identity, false);

                return new JsonResponse(['data' => $this->documents->metadata($this->attachments->owner($context), $document)->toArray()], headers: ['Cache-Control' => 'private, no-store']);
            });
        }
        $grant = $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $document): DocumentDownload {
            $context = $this->context($request, $identity, true);

            return $this->documents->authorizeDownload($this->attachments->owner($context), $document);
        });
        try {
            $stream = $this->objects->openVerified($grant->object);
        } catch (StorageUnavailable) {
            throw new HttpException(503);
        } catch (StorageConflict) {
            throw new HttpException(409);
        }
        try {
            $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $grant): void {
                $context = $this->context($request, $identity, true);
                $this->documents->consumeDownload($this->attachments->owner($context), $grant);
            });
        } catch (Throwable $failure) {
            fclose($stream);
            throw $failure;
        }

        return new StreamedResponse(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200,
            ['Content-Type' => $grant->document->format->mime(), 'Content-Length' => (string) $grant->object->size,
                'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $grant->document->filename, 'document.'.$grant->document->format->value),
                'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function context(Request $request, AuthorizedIdentity $identity, bool $download): CommercialContext
    {
        $staff = $request->is('api/v1/admin/*');
        if (($staff && $identity->kind !== 'staff') || (! $staff && $identity->kind !== 'customer')) {
            throw new AuthorizationException;
        }
        $contact = $identity->kind === 'customer' ? $this->contacts->currentForIdentity($identity->id) : null;
        if ($identity->kind === 'customer' && $contact === null) {
            throw new AuthorizationException;
        }
        $actor = new IntakeActor($identity->id, $contact?->customerId, $identity->verifiedEmail, $identity->permissions);
        $record = $this->intake->find($actor, $this->parameter($request, 'projectRequest'), true);
        $correlation = $request->attributes->get('request_id');
        $context = $this->contexts->context($record, $actor, is_string($correlation) ? $correlation : (string) Str::uuid7(), false);
        $this->attachments->readable($context, $this->parameter($request, 'proposal'), $this->parameter($request, 'document'), $download);

        return $context;
    }

    private function parameter(Request $request, string $name): string
    {
        $value = $request->route($name);

        return is_string($value) ? $value : '';
    }
}
