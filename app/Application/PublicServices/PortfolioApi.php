<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Http\Requests\PortfolioRequest;
use App\Modules\Identity\Actions\WithAuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Security\AuthLimiter;
use App\Modules\Identity\Security\SessionSecurity;
use App\Modules\PublicPortfolio\Actions\ManagePortfolio;
use App\Modules\PublicPortfolio\Actions\ReadPortfolio;
use App\Modules\PublicPortfolio\Actions\UploadPortfolioImage;
use App\Modules\PublicPortfolio\Contracts\PortfolioStorage;
use App\Modules\PublicPortfolio\Data\PortfolioActor;
use App\Modules\PublicPortfolio\Models\PortfolioAsset;
use App\Modules\PublicPortfolio\PortfolioPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class PortfolioApi
{
    public function __construct(private WithAuthorizedIdentity $identity, private SessionSecurity $sessions, private AuthLimiter $limiter,
        private ManagePortfolio $manage, private ReadPortfolio $read, private UploadPortfolioImage $upload, private PortfolioStorage $storage) {}

    public function handle(PortfolioRequest $request): Response
    {
        $operation = $request->operation();
        $id = $this->route($request, 'project');
        $image = $this->route($request, 'image');
        if (str_starts_with($operation, 'public_')) {
            $this->limiter->consume([['key' => 'portfolio:public:'.$request->ip(), 'maximum' => 240, 'seconds' => 60]]);
            if ($operation === 'public_image') {
                return new Response($this->read->image($image, $this->route($request, 'variant')), 200,
                    ['Content-Type' => 'image/webp', 'Cache-Control' => PortfolioPolicy::CACHE_CONTROL, 'X-Content-Type-Options' => 'nosniff']);
            }
            $result = match ($operation) {
                'public_list' => $this->read->page($request->integer('limit', 12), $this->nullable($request, 'category'), $this->nullable($request, 'cursor')),
                'public_detail' => ['data' => $this->read->detail($id)],
                'public_categories' => ['data' => $this->read->categories()],
                default => throw new HttpException(404),
            };

            return new JsonResponse($result, headers: ['Cache-Control' => PortfolioPolicy::CACHE_CONTROL]);
        }
        if ($operation === 'upload') {
            return $this->uploadContent($request, $id, $image);
        }

        return $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $operation, $id, $image): JsonResponse {
            $actor = $this->actor($identity, $request);
            $this->limiter->consume([['key' => 'portfolio:admin:'.$actor->id, 'maximum' => 120, 'seconds' => 60]]);
            if ($operation === 'list') {
                return new JsonResponse($this->manage->page($actor, $request->integer('limit', 12), $this->nullable($request, 'status'), $this->nullable($request, 'cursor')),
                    headers: ['Cache-Control' => 'private, no-store']);
            }
            $requestId = $request->attributes->getString('request_id');
            $etag = $request->header('If-Match');
            if ($operation === 'publish' || $operation === 'unpublish') {
                $data = $this->manage->publication($actor, $id, $operation === 'publish', $etag, $request->header('Idempotency-Key', ''), $requestId);
            } elseif ($operation === 'reserve' || $operation === 'image_status') {
                $asset = $operation === 'reserve' ? $this->manage->reserve($actor, $id, $request->validated(), $etag, $requestId) : $this->manage->asset($actor, $id, $image);
                $data = $this->manage->imageRecord($asset);
            } else {
                $project = match ($operation) {
                    'create' => $this->manage->create($actor, $request->validated(), $requestId),
                    'update' => $this->manage->update($actor, $id, $request->validated(), $etag, $requestId),
                    'remove' => $this->manage->remove($actor, $id, $image, $etag, $requestId),
                    'detail' => $this->manage->project($actor, $id),
                    default => throw new HttpException(404),
                };
                $data = $this->manage->record($project);
            }

            return $this->response($data, in_array($operation, ['create', 'reserve', 'publish'], true) ? 201 : 200);
        });
    }

    private function uploadContent(PortfolioRequest $request, string $projectId, string $imageId): JsonResponse
    {
        $etag = $request->header('If-Match');
        $asset = $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $projectId, $imageId, $etag): PortfolioAsset {
            $actor = $this->actor($identity, $request);
            $this->limiter->consume([['key' => 'portfolio:upload:'.$actor->id, 'maximum' => 20, 'seconds' => 3600]]);

            return $this->upload->authorize($actor, $projectId, $imageId, $etag);
        });
        $stream = $request->getContent(true);
        if (! is_resource($stream)) {
            throw new HttpException(400);
        }
        $bytes = stream_get_contents($stream, PortfolioPolicy::MAX_BYTES + 1);
        if ($bytes === false) {
            throw new HttpException(400);
        }
        if (strlen($bytes) > PortfolioPolicy::MAX_BYTES) {
            throw new HttpException(413);
        }
        if (strlen($bytes) !== $asset->byte_size || ! hash_equals($asset->sha256, hash('sha256', $bytes))) {
            throw new HttpException(422);
        }
        $version = $this->storage->put($imageId, 'source', $bytes);

        return $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $projectId, $imageId, $etag, $version): JsonResponse {
            // Recheck the live session and permissions after potentially slow storage I/O.
            $asset = $this->upload->finish($this->actor($identity, $request), $projectId, $imageId, $etag, $version, $request->attributes->getString('request_id'));

            return $this->response($this->manage->imageRecord($asset), 202);
        });
    }

    private function actor(AuthorizedIdentity $identity, PortfolioRequest $request): PortfolioActor
    {
        if ($identity->kind !== 'staff' || ! $identity->verifiedEmail || $request->session()->get('identity.mfa_verified') !== true) {
            throw new AuthorizationException;
        }

        return new PortfolioActor($identity->id, $identity->permissions, $this->sessions->hasRecentPassword($request));
    }

    /** @param array<string,mixed> $data */
    private function response(array $data, int $status): JsonResponse
    {
        $etag = $data['etag'] ?? null;
        $headers = ['Cache-Control' => 'private, no-store'];
        if (is_string($etag)) {
            $headers['ETag'] = $etag;
        }

        return new JsonResponse(['data' => $data], $status, $headers);
    }

    private function nullable(PortfolioRequest $request, string $key): ?string
    {
        return is_string($request->input($key)) ? $request->string($key)->toString() : null;
    }

    private function route(PortfolioRequest $request, string $key): string
    {
        $value = $request->route($key);

        return is_string($value) ? $value : '';
    }
}
