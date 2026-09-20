<?php

declare(strict_types=1);

namespace App\Application\Projects;

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
use App\Modules\Projects\Actions\ProjectDocuments;
use App\Modules\Projects\Actions\ProjectStore;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final readonly class ProjectDocumentApi
{
    public function __construct(private WithAuthorizedIdentity $identity, private CustomerContactReader $contacts,
        private ProjectStore $projects, private ProjectDocuments $attachments, private DocumentService $documents,
        private StoreUpload $uploads, private PrivateObjectStore $objects, private AuthLimiter $limiter) {}

    /** @param array<string,mixed> $input */
    public function handle(Request $request, string $operation, array $input): Response
    {
        try {
            $correlation = $request->attributes->get('request_id');
            $correlation = is_string($correlation) ? $correlation : (string) Str::uuid7();
            if ($operation === 'content') {
                return $this->upload($request, $correlation);
            }
            if ($operation === 'download') {
                return $this->download($request, $correlation);
            }

            return $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $operation, $input, $correlation): JsonResponse {
                [$actor, $project] = $this->context($request, $identity);
                $owner = $this->attachments->owner($project, $actor, $correlation);
                $id = $this->parameter($request, 'document');
                if ($operation === 'list') {
                    $page = $this->page($input['page'] ?? 1, 100000);
                    $perPage = $this->page($input['per_page'] ?? 25, 100);

                    return new JsonResponse($this->attachments->listing($project, $actor, $correlation, $page, $perPage),
                        headers: $this->headers($project));
                }
                $status = 200;
                if ($operation === 'reserve') {
                    $filename = $input['filename'] ?? null;
                    $bytes = $input['bytes'] ?? null;
                    $sha256 = $input['sha256'] ?? null;
                    $visibility = $input['visibility'] ?? null;
                    $key = $request->header('Idempotency-Key');
                    if (! is_string($filename) || ! is_int($bytes) || ! is_string($sha256) || ! is_string($visibility) || ! is_string($key)) {
                        throw new HttpException(422);
                    }
                    $this->charge($actor, 'reserve');
                    $reservation = $this->attachments->reserve($project, $actor, $request->header('If-Match'), $filename,
                        $bytes, $sha256, $visibility, $key, $correlation);
                    $view = $this->documents->metadata($owner, $reservation->documentId);
                    $status = 201;
                } else {
                    $visibility = $this->attachments->readable($project, $actor, $id);
                    $view = match ($operation) {
                        'metadata' => $this->documents->metadata($owner, $id),
                        'remove' => $this->attachments->remove($project, $actor, $id, $request->header('If-Match'), $correlation),
                        'retry' => $this->retry($project, $actor, $id, $request, $correlation),
                        default => throw new HttpException(404),
                    };
                }

                return $this->response($view, $project, $visibility, $status);
            });
        } catch (StorageUnavailable) {
            throw new HttpException(503);
        } catch (StorageConflict) {
            throw new HttpException(409);
        }
    }

    private function retry(Project $project, ProjectActor $actor, string $id, Request $request, string $correlation): DocumentView
    {
        $this->charge($actor, 'retry');

        return $this->attachments->retry($project, $actor, $id, $request->header('If-Match'), $correlation);
    }

    private function upload(Request $request, string $correlation): JsonResponse
    {
        $id = $this->parameter($request, 'document');
        $reservation = $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $correlation, $id): UploadReservation {
            [$actor, $project] = $this->context($request, $identity);
            $reservation = $this->attachments->upload($project, $actor, $id, $request->header('If-Match'), $correlation);
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

        return $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $correlation, $id, $object): JsonResponse {
            [$actor, $project] = $this->context($request, $identity);
            $view = $this->attachments->finalize($project, $actor, $id, $object, $request->header('If-Match'), $correlation);

            return $this->response($view, $project, $this->attachments->readable($project, $actor, $id));
        });
    }

    private function download(Request $request, string $correlation): StreamedResponse
    {
        $id = $this->parameter($request, 'document');
        $grant = $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $correlation, $id): DocumentDownload {
            [$actor, $project] = $this->context($request, $identity);
            $this->attachments->readable($project, $actor, $id);

            return $this->documents->authorizeDownload($this->attachments->owner($project, $actor, $correlation), $id);
        });
        $stream = $this->objects->openVerified($grant->object);
        try {
            $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $correlation, $grant): void {
                [$actor, $project] = $this->context($request, $identity);
                $this->attachments->readable($project, $actor, $grant->document->id);
                $this->documents->consumeDownload($this->attachments->owner($project, $actor, $correlation), $grant);
                $this->projects->event($project, 'document_accessed', $actor, $correlation);
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
        }, 200, ['Content-Type' => $grant->document->format->mime(), 'Content-Length' => (string) $grant->object->size,
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $grant->document->filename, 'document.'.$grant->document->format->value),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache',
            'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    /** @return array{ProjectActor,Project} */
    private function context(Request $request, AuthorizedIdentity $identity): array
    {
        $staff = $request->is('api/v1/admin/*');
        if (($staff && $identity->kind !== 'staff') || (! $staff && $identity->kind !== 'customer')) {
            throw new AuthorizationException;
        }
        $contact = $identity->kind === 'customer' ? $this->contacts->currentForIdentity($identity->id) : null;
        if ($identity->kind === 'customer' && $contact === null) {
            throw new AuthorizationException;
        }
        $actor = new ProjectActor($identity->id, $contact?->customerId, $identity->verifiedEmail, false, $identity->permissions);

        return [$actor, $this->projects->find($actor, $this->parameter($request, 'project'))];
    }

    private function charge(ProjectActor $actor, string $purpose): void
    {
        $this->limiter->consume([['key' => 'projects:documents:'.$purpose.':'.$actor->id, 'maximum' => 20, 'seconds' => 60]]);
    }

    private function response(DocumentView $view, Project $project, string $visibility, int $status = 200): JsonResponse
    {
        $headers = $this->headers($project);
        if ($status === 201) {
            $headers['Location'] = '/api/v1/admin/projects/'.$project->id.'/documents/'.$view->id;
        }

        return new JsonResponse(['data' => [...$view->toArray(), 'visibility' => $visibility]], $status, $headers);
    }

    /** @return array<string,string> */
    private function headers(Project $project): array
    {
        return ['ETag' => VersionPrecondition::etag($project->id, $project->lock_version), 'Cache-Control' => 'private, no-store'];
    }

    private function parameter(Request $request, string $name): string
    {
        $value = $request->route($name);

        return is_string($value) ? $value : '';
    }

    private function page(mixed $input, int $maximum): int
    {
        $value = filter_var($input, FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw new HttpException(422);
        }

        return $value;
    }
}
