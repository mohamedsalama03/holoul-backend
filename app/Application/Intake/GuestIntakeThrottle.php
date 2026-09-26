<?php

declare(strict_types=1);

namespace App\Application\Intake;

use App\Infrastructure\Http\SessionResponse;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Security\AuthLimiter;
use App\Modules\Identity\Security\IdentityInput;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class GuestIntakeThrottle
{
    public function __construct(private AuthLimiter $limiter, private RecordAuditEvent $audit) {}

    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $operation = $request->route('operation');
        $publicTaxonomy = in_array($operation, ['categories', 'subcategories'], true);
        if (! $publicTaxonomy && $operation !== 'claim') {
            // CSRF/capability reads are necessary; guest work never changes authentication state.
            SessionResponse::discard($request);
        }
        if (! $publicTaxonomy && $operation !== 'claim' && (Auth::guard('web')->check()
            || $request->session()->has('identity.pending_user_id') || $request->session()->has('identity.session_id')
            || $request->session()->has('identity.auth_version'))) {
            throw new HttpException(403);
        }
        $purpose = is_string($operation) ? $operation : 'unknown';
        $maximum = match ($purpose) {
            'create' => 5, 'claim' => 10, 'submit' => 15, default => 60
        };
        $seconds = in_array($purpose, ['create', 'submit'], true) ? 3600 : 60;
        $buckets = [
            ['key' => 'guest-intake:'.$purpose.':ip:'.$request->ip(), 'maximum' => $maximum, 'seconds' => $seconds],
        ];
        if (! $publicTaxonomy) {
            $buckets[] = ['key' => 'guest-intake:'.$purpose.':session:'.$request->session()->getId(), 'maximum' => $maximum, 'seconds' => $seconds];
        }
        if ($purpose === 'create') {
            $buckets[] = ['key' => 'guest-intake:create:global', 'maximum' => 100, 'seconds' => 3600];
        }
        try {
            $this->limiter->consume($buckets);
        } catch (HttpException $failure) {
            $id = IdentityInput::requestId($request);
            $this->audit->handle('intake.guest_throttled', 'intake.attempt', $id, $id);
            throw $failure;
        }

        return $next($request);
    }
}
