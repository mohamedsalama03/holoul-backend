<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\AuthLimiter;
use App\Modules\Identity\Security\IdentityInput;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityThrottle
{
    public function __construct(private readonly AuthLimiter $limiter) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $purpose): Response
    {
        $email = $request->input('email');
        $account = is_string($email) ? IdentityInput::email($email) : 'invalid';
        if ($purpose === 'login' && $request->is('api/v1/auth/username-login')) {
            $username = $request->input('username');
            $username = is_string($username) ? strtolower(trim($username)) : '';
            // Both login aliases consume the existing email account bucket.
            $user = strlen($username) <= 40 ? User::query()->where('username', $username)->first() : null;
            $account = $user->email ?? 'username:'.$username;
        }
        $pending = $request->session()->get('identity.pending_user_id');
        $principal = $request->user()?->getAuthIdentifier();
        $identity = is_string($principal) ? $principal : (is_string($pending) ? $pending : $account);
        $token = $request->input('token');
        $token = is_string($token) ? $token : 'invalid';
        $buckets = match ($purpose) {
            'login' => [
                ['key' => 'login:account:'.$account, 'maximum' => 5, 'seconds' => 60],
                ['key' => 'login:ip:'.$request->ip(), 'maximum' => 30, 'seconds' => 60],
            ],
            'forgot', 'resend' => [
                ['key' => $purpose.':account:'.$account, 'maximum' => 3, 'seconds' => 3600],
                ['key' => $purpose.':ip:'.$request->ip(), 'maximum' => 30, 'seconds' => 3600],
            ],
            'register' => [['key' => 'register:ip:'.$request->ip(), 'maximum' => 10, 'seconds' => 3600]],
            'verify', 'reset' => [
                ['key' => $purpose.':token:'.$token, 'maximum' => 5, 'seconds' => 60],
                ['key' => $purpose.':ip:'.$request->ip(), 'maximum' => 30, 'seconds' => 60],
            ],
            default => [
                ['key' => $purpose.':identity:'.$identity, 'maximum' => 5, 'seconds' => 60],
                ['key' => $purpose.':ip:'.$request->ip(), 'maximum' => 30, 'seconds' => 60],
            ],
        };
        $attempt = $this->limiter->consume($buckets);
        $started = hrtime(true);
        try {
            return $next($request);
        } finally {
            // Account-independent response floors reduce the account-existence
            // timing signal; repeated first-factor attempts add bounded delay.
            if (in_array($purpose, ['login', 'register', 'forgot', 'resend', 'mfa'], true)) {
                $floor = 450_000 + ($purpose === 'login' ? min($attempt, 5) * 100_000 : 0) + random_int(0, 30_000);
                $remaining = $floor - intdiv(hrtime(true) - $started, 1000);
                if ($remaining > 0) {
                    usleep($remaining);
                }
            }
        }
    }
}
