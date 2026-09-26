<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Data\SafeAuditMetadata;
use App\Modules\Audit\Queries\InvestigateAudit;
use App\Modules\Identity\Actions\WithAuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Security\AuthLimiter;
use App\Modules\Reporting\Actions\OperationalReports;
use App\Modules\Reporting\Data\ReportWindow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ReportingApi
{
    public function __construct(private WithAuthorizedIdentity $identities, private OperationalReports $reports,
        private InvestigateAudit $investigation, private RecordAuditEvent $audit, private AuthLimiter $limits) {}

    /** @param array<string,mixed> $input */
    public function handle(Request $request, string $report, array $input): JsonResponse
    {
        return DB::transaction(function () use ($request, $report, $input): JsonResponse {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return $this->authorized($request, $report, $input);
        }, 2);
    }

    /** @param array<string,mixed> $input */
    private function authorized(Request $request, string $report, array $input): JsonResponse
    {
        return $this->identities->handle($request, function (AuthorizedIdentity $actor) use ($request, $report, $input): JsonResponse {
            if ($actor->kind !== 'staff' || ! $actor->verifiedEmail || ! $actor->allows($report === 'audit' ? 'audit.investigate' : 'reporting.read')) {
                throw new AuthorizationException;
            }
            $this->limits->consume([['key' => 'reporting:'.$actor->id, 'maximum' => 60, 'seconds' => 60]]);
            // Bound slow/adversarial aggregates while leaving optional reporting independent of core operations.
            DB::statement("SET LOCAL statement_timeout='5s'");
            $window = ReportWindow::fromInput($input);
            if ($report === 'audit') {
                $filters = [];
                foreach (['event_type', 'actor_id', 'subject_type', 'subject_id', 'request_id'] as $key) {
                    if (is_string($input[$key] ?? null)) {
                        $filters[$key] = $input[$key];
                    }
                }
                $result = $this->investigation->read($window->from, $window->until, $filters,
                    is_int($input['limit'] ?? null) ? $input['limit'] : 25, is_string($input['cursor'] ?? null) ? $input['cursor'] : null);
                $result['meta']['window'] = $window->toArray();
                $correlation = $request->attributes->get('request_id');
                $this->audit->handle('audit.investigation_viewed', 'identity.user', $actor->id, is_string($correlation) ? $correlation : (string) Str::uuid7(),
                    $actor->id, new SafeAuditMetadata(['affected_count' => count($result['data'])]));
            } else {
                $result = $this->reports->read($report, $window, is_string($input['category_id'] ?? null) ? $input['category_id'] : null,
                    is_string($input['subcategory_id'] ?? null) ? $input['subcategory_id'] : null);
            }

            return new JsonResponse($result, headers: ['Cache-Control' => 'private, no-store']);
        });
    }
}
