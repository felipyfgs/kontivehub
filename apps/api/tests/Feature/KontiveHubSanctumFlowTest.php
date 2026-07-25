<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class KontiveHubSanctumFlowTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('approvedOrigins')]
    public function test_csrf_login_protected_route_and_logout_work_for_approved_origin(string $origin): void
    {
        config()->set('session.driver', 'database');
        $this->resetRequestState();
        $this->withCredentials();

        $user = User::factory()->create([
            'email' => 'auth-flow@example.test',
            'password' => 'password',
        ]);

        $csrf = $this->withHeader('Origin', $origin)
            ->get('https://api.kontivehub.com.br/sanctum/csrf-cookie');

        $csrf->assertNoContent();
        $csrf->assertHeader('Access-Control-Allow-Origin', $origin);
        $csrf->assertHeader('Access-Control-Allow-Credentials', 'true');

        $sessionCookie = $this->responseCookie($csrf->baseResponse, 'kontivehub_session');
        $xsrfCookie = $this->responseCookie($csrf->baseResponse, 'XSRF-TOKEN');
        $this->assertSame('.kontivehub.com.br', $sessionCookie->getDomain());
        $this->assertTrue($sessionCookie->isSecure());
        $this->assertTrue($sessionCookie->isHttpOnly());
        $this->assertSame('lax', $sessionCookie->getSameSite());

        $login = $this->withUnencryptedCookies([
            $sessionCookie->getName() => $sessionCookie->getValue(),
            $xsrfCookie->getName() => $xsrfCookie->getValue(),
        ])->withHeaders([
            'Origin' => $origin,
            'Referer' => $origin.'/login',
            'X-XSRF-TOKEN' => urldecode($xsrfCookie->getValue()),
        ])->postJson('https://api.kontivehub.com.br/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $login->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', $origin);

        $authenticatedSession = $this->responseCookie($login->baseResponse, 'kontivehub_session');
        $this->resetRequestState();
        $this->resetClientCookies();

        $this->withHeaders([
            'Origin' => $origin,
            'Referer' => $origin.'/',
        ])->getJson('https://api.kontivehub.com.br/api/v1/me')
            ->assertUnauthorized();

        $this->resetRequestState();
        $this->withUnencryptedCookies([
            $authenticatedSession->getName() => $authenticatedSession->getValue(),
            $xsrfCookie->getName() => $xsrfCookie->getValue(),
        ])->withHeaders([
            'Origin' => $origin,
            'Referer' => $origin.'/',
        ])->getJson('https://api.kontivehub.com.br/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);

        $this->resetRequestState();

        $this->withUnencryptedCookies([
            $authenticatedSession->getName() => $authenticatedSession->getValue(),
            $xsrfCookie->getName() => $xsrfCookie->getValue(),
        ])->withHeaders([
            'Origin' => $origin,
            'Referer' => $origin.'/',
            'X-XSRF-TOKEN' => urldecode($xsrfCookie->getValue()),
        ])->postJson('https://api.kontivehub.com.br/logout')
            ->assertNoContent();

        $this->resetRequestState();
        $this->resetClientCookies();

        $this->withUnencryptedCookie(
            $authenticatedSession->getName(),
            $authenticatedSession->getValue(),
        )->getJson('https://api.kontivehub.com.br/api/v1/me')
            ->assertUnauthorized();
    }

    public static function approvedOrigins(): array
    {
        return [
            'app' => ['https://app.kontivehub.com.br'],
            'portal' => ['https://portal.kontivehub.com.br'],
        ];
    }

    private function responseCookie(Response $response, string $name): Cookie
    {
        $cookie = collect($response->headers->getCookies())
            ->first(static fn (Cookie $cookie): bool => $cookie->getName() === $name);

        $this->assertInstanceOf(Cookie::class, $cookie);

        return $cookie;
    }

    private function resetClientCookies(): void
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }

    private function resetRequestState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }
}
