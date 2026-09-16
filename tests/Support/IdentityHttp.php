<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait IdentityHttp
{
    /** @var array<string, string> */
    private array $browserCookies = [];

    private string $browserIp = '192.0.2.1';

    private function initializeBrowser(): void
    {
        Config::set('identity.origin', 'https://localhost:8443');
        Config::set('app.url', 'https://localhost:8443');
        $this->browserCookies = [];
        $this->browserIp = '192.0.2.'.random_int(1, 250);
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
    }

    /** @param array<string, mixed> $data
     * @param  array<string, string|null>  $headers
     */
    private function browser(string $method, string $path, array $data = [], array $headers = [], bool $csrf = true): TestResponse
    {
        // A new PHP request/guard/store each time: never actingAs or test-only CSRF bypass.
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'localhost:8443', 'SERVER_PORT' => '8443',
            'REMOTE_ADDR' => $this->browserIp, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'https://localhost:8443'];
        if ($csrf && isset($this->browserCookies['XSRF-TOKEN'])) {
            $server['HTTP_X_XSRF_TOKEN'] = $this->browserCookies['XSRF-TOKEN'];
        }
        foreach ($headers as $name => $value) {
            $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
            if ($value === null) {
                unset($server[$key]);
            } else {
                $server[$key] = $value;
            }
        }
        $response = $this->call($method, 'https://localhost:8443'.$path, [], $this->browserCookies, [], $server, json_encode($data, JSON_THROW_ON_ERROR));
        foreach ($response->headers->getCookies() as $cookie) {
            $this->browserCookies[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }

    private function sessionId(): string
    {
        $cookie = $this->browserCookies['__Host-holoul_session'];

        return CookieValuePrefix::remove(app('encrypter')->decrypt($cookie, false));
    }

    private function customerUser(): User
    {
        return User::query()->create(['full_name' => 'Test Customer', 'email' => Str::uuid7().'@example.test',
            'email_display' => 'Customer@example.test', 'password' => 'Correct-Horse-72-River',
            'kind' => 'customer', 'enabled' => true, 'auth_version' => 1]);
    }

    private function signIn(User $user): TestResponse
    {
        return $this->browser('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'Correct-Horse-72-River']);
    }
}
