<?php

declare(strict_types=1);

namespace App\Application\Intake;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Categories\Actions\ManageTaxonomy;
use App\Modules\Categories\Contracts\TaxonomyReader;
use App\Modules\Categories\Data\TaxonomyActor;
use App\Modules\Categories\Data\TaxonomyChanges;
use App\Modules\Customers\Contracts\CustomerContactReader;
use App\Modules\Identity\Actions\WithAuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedStaffReader;
use App\Modules\ProjectIntake\Actions\AssignRequest;
use App\Modules\ProjectIntake\Actions\InformationWorkflow;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Actions\TransitionRequest;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\ProjectIntake\Queries\ReadIntake;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class IntakeApi
{
    public function __construct(
        private WithAuthorizedIdentity $identity, private CustomerContactReader $contacts, private AuthorizedStaffReader $staff,
        private ManageDraft $drafts, private SubmitRequest $submissions, private TransitionRequest $transitions,
        private AssignRequest $assignments, private InformationWorkflow $information, private ReadIntake $reads,
        private IntakeStore $store, private ManageTaxonomy $taxonomy, private TaxonomyReader $taxonomyReads,
    ) {}

    /** @param array<string,mixed> $input */
    public function handle(Request $request, string $operation, array $input): JsonResponse
    {
        return $this->identity->handle($request, function (AuthorizedIdentity $identity) use ($request, $operation, $input): JsonResponse {
            $requestId = $request->attributes->get('request_id');
            $requestId = is_string($requestId) ? $requestId : (string) Str::uuid7();
            if (str_starts_with($operation, 'taxonomy.')) {
                return $this->taxonomy($identity, $request, $operation, $input, $requestId);
            }
            if ((str_starts_with($operation, 'customer.') && $identity->kind !== 'customer')
                || (str_starts_with($operation, 'staff.') && $identity->kind !== 'staff')) {
                throw new AuthorizationException;
            }
            $contact = $identity->kind === 'customer' ? $this->contacts->currentForIdentity($identity->id) : null;
            if ($identity->kind === 'customer' && $contact === null) {
                throw new AuthorizationException;
            }
            $actor = new IntakeActor($identity->id, $contact?->customerId, $identity->verifiedEmail, $identity->permissions);
            $id = $this->parameter($request, 'projectRequest');
            $etag = $request->header('If-Match');
            $limit = is_int($input['limit'] ?? null) ? $input['limit'] : 25;
            if (str_ends_with($operation, '.list')) {
                return new JsonResponse($this->reads->listing($actor, $input));
            }
            if (str_ends_with($operation, '.reference')) {
                $id = $this->reads->byReference($actor, $this->parameter($request, 'reference'));

                return $this->detail($actor, $id, $requestId);
            }
            if (in_array($operation, ['customer.detail', 'staff.detail', 'customer.nested'], true)) {
                return $this->detail($actor, $id, $requestId, $operation === 'customer.nested' ? $this->parameter($request, 'customer') : null);
            }
            if (str_ends_with($operation, '.revision')) {
                if ($actor->customerId === null) {
                    $this->store->event('staff_viewed', $this->store->find($actor, $id), $actor, $requestId);
                }

                return new JsonResponse(['data' => $this->reads->revisionDetail($actor, $id, $this->parameter($request, 'revision'))]);
            }
            if (str_ends_with($operation, '.revisions')) {
                if ($actor->customerId === null) {
                    $this->store->event('staff_viewed', $this->store->find($actor, $id), $actor, $requestId);
                }

                return new JsonResponse($this->reads->revisions($actor, $id, is_int($input['after'] ?? null) ? $input['after'] : 0, $limit));
            }
            if (str_ends_with($operation, '.information') || str_ends_with($operation, '.history')) {
                if ($actor->customerId === null) {
                    $this->store->event('staff_viewed', $this->store->find($actor, $id), $actor, $requestId);
                }
                $after = is_string($input['after'] ?? null) ? $input['after'] : null;

                return new JsonResponse(str_ends_with($operation, '.history') ? $this->reads->history($actor, $id, $after, $limit) : $this->reads->information($actor, $id, $after, $limit));
            }
            if ($operation === 'customer.submit') {
                $receipt = $this->submissions->handle($actor, $id, $etag, $request->header('Idempotency-Key'), $requestId);

                return new JsonResponse(['data' => ['request_id' => $receipt->request_id, 'reference' => $receipt->reference,
                    'revision_id' => $receipt->revision_id, 'revision_number' => $receipt->revision_number,
                    'version' => $receipt->result_version, 'state' => $receipt->result_state]], 201,
                    ['ETag' => VersionPrecondition::etag($id, $receipt->result_version ?? 1), 'Location' => '/api/v1/project-requests/'.$id.'/revisions/'.$receipt->revision_id]);
            }
            if ($operation === 'staff.assignments') {
                $this->store->event('staff_viewed', $this->store->find($actor, $id), $actor, $requestId);

                return new JsonResponse($this->reads->assignments($actor, $id, is_string($input['after'] ?? null) ? $input['after'] : null, $limit));
            }
            if ($operation === 'staff.assign') {
                $record = $this->store->mutable($actor, $id, $etag);
                $this->store->policy->staff($actor, $record, 'intake.assign', false);
                $candidate = $this->staff->forIntakeAssignment($this->text($input, 'assignee_id'));
                if ($candidate === null) {
                    throw ValidationException::withMessages(['assignee_id' => 'Not an eligible assignee.']);
                }

                return $this->mutation($this->assignments->handle($actor, $id, $etag, $candidate->id, $requestId), $actor);
            }
            $record = match ($operation) {
                'customer.create' => $this->drafts->create($actor, $input, $requestId),
                'customer.update' => $this->drafts->update($actor, $id, $etag, $input, $requestId),
                'customer.amend' => $this->drafts->amend($actor, $id, $etag, $requestId),
                'customer.withdraw' => $this->transitions->handle($actor, $id, $etag, 'withdraw', is_string($input['message'] ?? null) ? $input['message'] : null, $requestId),
                'staff.review' => $this->transitions->handle($actor, $id, $etag, 'review', null, $requestId),
                'staff.discovery' => $this->transitions->handle($actor, $id, $etag, 'discovery', null, $requestId),
                'staff.reject' => $this->transitions->handle($actor, $id, $etag, 'reject', $this->text($input, 'message'), $requestId),
                'staff.ask' => $this->information->ask($actor, $id, $etag, $this->text($input, 'message'), $requestId),
                'customer.response' => $this->information->respond($actor, $id, $this->parameter($request, 'information'), $etag, $this->text($input, 'message'), $requestId),
                'staff.acknowledge' => $this->information->acknowledge($actor, $id, $this->parameter($request, 'information'), $etag, $requestId),
                default => throw new HttpException(404),
            };

            return $this->mutation($record, $actor, $operation === 'customer.create' ? 201 : 200);
        });
    }

    /** @param array<string,mixed> $input */
    private function taxonomy(AuthorizedIdentity $identity, Request $request, string $operation, array $input, string $requestId): JsonResponse
    {
        $actor = new TaxonomyActor($identity->id, $identity->kind === 'staff' && $identity->allows('taxonomy.manage'));
        $limit = is_int($input['limit'] ?? null) ? $input['limit'] : 25;
        $cursor = is_string($input['cursor'] ?? null) ? $input['cursor'] : null;
        $category = $this->parameter($request, 'category');
        if (in_array($operation, ['taxonomy.category', 'taxonomy.subcategory'], true)) {
            $record = $operation === 'taxonomy.category' ? $this->taxonomy->category($actor, $category)
                : $this->taxonomy->subcategory($actor, $this->parameter($request, 'subcategory'));

            return new JsonResponse(['data' => $record->toArray()], 200, ['ETag' => VersionPrecondition::etag($record->id, $record->lockVersion)]);
        }
        if (in_array($operation, ['taxonomy.categories', 'taxonomy.subcategories', 'taxonomy.admin_categories', 'taxonomy.admin_subcategories'], true)) {
            $page = match ($operation) {
                'taxonomy.categories' => $this->taxonomyReads->categories($limit, $cursor),
                'taxonomy.subcategories' => $this->taxonomyReads->subcategories($category, $limit, $cursor),
                'taxonomy.admin_categories' => $this->taxonomy->categories($actor, $limit, $cursor),
                'taxonomy.admin_subcategories' => $this->taxonomy->subcategories($actor, $category, $limit, $cursor),
            };

            return new JsonResponse(['data' => array_map(fn ($row): array => $row->toArray(), $page->items), 'meta' => ['next_cursor' => $page->nextCursor]]);
        }
        $create = str_starts_with($operation, 'taxonomy.create_');
        if ($create) {
            $name = $this->text($input, 'name');
            $slug = $this->text($input, 'slug');
            $active = $input['active'] === true;
            $order = is_int($input['display_order']) ? $input['display_order'] : throw ValidationException::withMessages(['display_order' => 'Integer required.']);
            $record = $operation === 'taxonomy.create_category'
                ? $this->taxonomy->createCategory($actor, $name, $slug, $active, $order, $requestId)
                : $this->taxonomy->createSubcategory($actor, $category, $name, $slug, $active, $order, $requestId);
        } else {
            $id = $operation === 'taxonomy.update_category' ? $category : $this->parameter($request, 'subcategory');
            $header = $request->header('If-Match');
            $expected = null;
            if ($header !== null) {
                if (preg_match('/\A"'.preg_quote($id, '/').':([1-9][0-9]{0,15})"\z/D', $header, $match) !== 1) {
                    throw new HttpException(412);
                }
                $expected = (int) $match[1];
            }
            $changes = new TaxonomyChanges(is_string($input['name'] ?? null) ? $input['name'] : null,
                is_bool($input['active'] ?? null) ? $input['active'] : null, is_int($input['display_order'] ?? null) ? $input['display_order'] : null);
            $record = $operation === 'taxonomy.update_category'
                ? $this->taxonomy->updateCategory($actor, $id, $changes, $expected, $requestId)
                : $this->taxonomy->updateSubcategory($actor, $id, $changes, $expected, $requestId);
        }

        $headers = ['ETag' => VersionPrecondition::etag($record->id, $record->lockVersion)];
        if ($create) {
            $headers['Location'] = '/api/v1/admin/'.($record->categoryId === null ? 'categories/' : 'subcategories/').$record->id;
        }

        return new JsonResponse(['data' => $record->toArray()], $create ? 201 : 200, $headers);
    }

    private function mutation(ProjectRequest $record, IntakeActor $actor, int $status = 200): JsonResponse
    {
        $headers = ['ETag' => VersionPrecondition::etag($record->id, $record->lock_version)];
        // A Location header on an ordinary 200 can become a FastCGI redirect.
        if ($status === 201) {
            $headers['Location'] = '/api/v1/project-requests/'.$record->id;
        }

        return new JsonResponse(['data' => $this->reads->summary($record, $actor)], $status, $headers);
    }

    private function detail(IntakeActor $actor, string $id, string $requestId, ?string $parent = null): JsonResponse
    {
        $data = $this->reads->detail($actor, $id, $requestId, $parent);
        $etag = is_string($data['etag']) ? $data['etag'] : '';

        return new JsonResponse(['data' => $data], 200, ['ETag' => $etag]);
    }

    private function parameter(Request $request, string $name): string
    {
        $value = $request->route($name);

        return is_string($value) ? $value : '';
    }

    /** @param array<string,mixed> $input */
    private function text(array $input, string $field): string
    {
        return is_string($input[$field] ?? null) ? $input[$field] : throw ValidationException::withMessages([$field => 'Text required.']);
    }
}
