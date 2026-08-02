<?php

namespace Tests\Feature;

use App\Enums\ApiRateLimit;
use App\Http\Middleware\EnsureRecentPasswordConfirmation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiRateLimitingTest extends TestCase
{
    public function test_every_api_rate_limit_is_registered_with_its_expected_minute_budget(): void
    {
        $expectedAttempts = [
            ApiRateLimit::InternalCommunicationGateway->value => [600],
            ApiRateLimit::CteEmitterPush->value => [
                (int) config('sefaz.cte_emitter_push.rate_limit_per_minute', 30),
                (int) config('sefaz.cte_emitter_push.ip_rate_limit_per_minute', 30),
            ],
            ApiRateLimit::PublicActivation->value => [20],
            ApiRateLimit::PublicOnboardingStatus->value => [20],
            ApiRateLimit::PublicOnboardingCompletion->value => [5],
            ApiRateLimit::AuthenticatedModerate->value => [30],
            ApiRateLimit::AuthenticatedStandard->value => [20],
            ApiRateLimit::AuthenticatedSensitive->value => [10],
            ApiRateLimit::AuthenticatedCritical->value => [5],
            ApiRateLimit::CommunicationMessageSend->value => [120],
            ApiRateLimit::CommunicationConversationListSnapshot->value => [
                max(1, (int) config('communication.conversation_list_snapshot.rate_limit_per_minute', 10)),
            ],
            ApiRateLimit::CommunicationProfilePicture->value => [
                max(1, (int) config('communication.profile_pictures.stream_rate_limit_per_minute', 600)),
                max(1, (int) config('communication.profile_pictures.stream_ip_rate_limit_per_minute', 1_200)),
            ],
            ApiRateLimit::AssistantAccess->value => [60],
            ApiRateLimit::AssistantChat->value => [30],
            ApiRateLimit::CteIntegrationTokenAdministration->value => [(int) config(
                'sefaz.cte_emitter_push.admin_token_rate_limit_per_minute',
                10,
            )],
        ];
        $request = Request::create('/api/v1/test', 'GET', server: ['REMOTE_ADDR' => '203.0.113.10']);

        foreach (ApiRateLimit::cases() as $rateLimit) {
            $resolver = RateLimiter::limiter($rateLimit);
            $limiterRequest = $rateLimit === ApiRateLimit::CommunicationConversationListSnapshot
                ? Request::create('/api/v1/test?snapshot=true', 'GET', server: ['REMOTE_ADDR' => '203.0.113.10'])
                : $request;

            $this->assertNotNull($resolver, "Limiter {$rateLimit->value} não registrado.");

            $resolved = $resolver($limiterRequest);
            $limits = is_array($resolved) ? $resolved : [$resolved];
            $this->assertCount(count($expectedAttempts[$rateLimit->value]), $limits);

            foreach ($limits as $index => $limit) {
                $this->assertInstanceOf(Limit::class, $limit);
                $this->assertSame($expectedAttempts[$rateLimit->value][$index], $limit->maxAttempts);
                $this->assertSame(60, $limit->decaySeconds);
            }
        }
    }

    public function test_rate_limit_keys_use_ip_for_public_calls_and_user_for_authenticated_calls(): void
    {
        $request = Request::create('/api/v1/test', 'GET', server: ['REMOTE_ADDR' => '203.0.113.20']);
        $publicResolver = RateLimiter::limiter(ApiRateLimit::PublicActivation);
        $authenticatedResolver = RateLimiter::limiter(ApiRateLimit::AuthenticatedStandard);
        $profilePictureResolver = RateLimiter::limiter(ApiRateLimit::CommunicationProfilePicture);

        $this->assertNotNull($publicResolver);
        $this->assertNotNull($authenticatedResolver);
        $this->assertNotNull($profilePictureResolver);
        $this->assertSame('ip:203.0.113.20', $publicResolver($request)->key);
        $this->assertSame('ip:203.0.113.20', $authenticatedResolver($request)->key);

        $user = new User;
        $user->forceFill(['id' => 42]);
        $request->setUserResolver(static fn (): User => $user);

        $this->assertSame('user:42', $authenticatedResolver($request)->key);

        $tenant = new Tenant;
        $tenant->forceFill(['id' => 73]);
        app(CurrentTenant::class)->bindSystem($tenant);
        $profileLimits = $profilePictureResolver($request);
        $this->assertIsArray($profileLimits);
        $this->assertSame('communication-profile-picture:user:42:tenant:73', $profileLimits[0]->key);
        $this->assertSame('communication-profile-picture:ip:203.0.113.20', $profileLimits[1]->key);
    }

    public function test_cte_emitter_push_limits_by_opaque_token_digest_and_separate_ip_ceiling(): void
    {
        config()->set('sefaz.cte_emitter_push.rate_limit_digest_key', 'rate-limit-test-key');
        $resolver = RateLimiter::limiter(ApiRateLimit::CteEmitterPush);
        $this->assertNotNull($resolver);

        $firstIp = $this->integrationRequest('cte_secret_one', '203.0.113.30');
        $secondIp = $this->integrationRequest('cte_secret_one', '203.0.113.31');
        $secondToken = $this->integrationRequest('cte_secret_two', '203.0.113.30');

        $firstLimits = $resolver($firstIp);
        $secondIpLimits = $resolver($secondIp);
        $secondTokenLimits = $resolver($secondToken);

        $this->assertIsArray($firstLimits);
        $this->assertIsArray($secondIpLimits);
        $this->assertIsArray($secondTokenLimits);
        $this->assertSame(
            'integration-token:'.hash_hmac('sha256', 'cte_secret_one', 'rate-limit-test-key'),
            $firstLimits[0]->key,
        );
        $this->assertSame($firstLimits[0]->key, $secondIpLimits[0]->key);
        $this->assertNotSame($firstLimits[1]->key, $secondIpLimits[1]->key);
        $this->assertNotSame($firstLimits[0]->key, $secondTokenLimits[0]->key);
        $this->assertSame($firstLimits[1]->key, $secondTokenLimits[1]->key);
        $this->assertStringNotContainsString('cte_secret', implode('|', [
            $firstLimits[0]->key,
            $firstLimits[1]->key,
            $secondIpLimits[0]->key,
            $secondTokenLimits[0]->key,
        ]));
    }

    public function test_limited_response_exposes_standard_headers_and_does_not_consume_another_risk_budget(): void
    {
        Route::middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedCritical))
            ->get('/api/_test/rate-limit/critical', static fn () => response()->json(['ok' => true]));
        Route::middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard))
            ->get('/api/_test/rate-limit/standard', static fn () => response()->json(['ok' => true]));

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->getJson('/api/_test/rate-limit/critical')
                ->assertOk()
                ->assertHeader('X-RateLimit-Limit', '5');
        }

        $this->getJson('/api/_test/rate-limit/critical')
            ->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Reset');

        $this->getJson('/api/_test/rate-limit/standard')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '20')
            ->assertHeader('X-RateLimit-Remaining', '19');
    }

    public function test_api_routes_do_not_use_numeric_throttle_middleware(): void
    {
        $numeric = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (preg_match('/(?:^|:)\\d+(?:,|$)/', $middleware) === 1) {
                    $numeric[] = implode('|', $route->methods()).' /'.$route->uri().' => '.$middleware;
                }
            }
        }

        $this->assertSame([], $numeric, 'Rotas API com throttle numérico: '.implode(', ', $numeric));
    }

    public function test_profile_picture_scheduling_route_uses_the_scoped_limiter(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/v1/communication/inboxes/{inbox}/contacts/profile-picture'
                && in_array('POST', $route->methods(), true),
        );

        $this->assertNotNull($route, 'Rota de agendamento de foto não registrada.');
        $this->assertContains(
            ThrottleRequests::using(ApiRateLimit::CommunicationProfilePicture),
            $route->gatherMiddleware(),
            'Agendamento de foto sem limiter por ator, tenant e IP.',
        );
    }

    public function test_sensitive_tenant_admin_routes_preserve_rate_limit_and_recent_password_boundaries(): void
    {
        $expectedRoutes = [
            [
                'method' => 'PATCH',
                'uri' => 'api/v1/tenant/members/{membership}',
                'rate_limit' => ApiRateLimit::AuthenticatedStandard,
            ],
            [
                'method' => 'POST',
                'uri' => 'api/v1/tenant/members/{membership}/deactivate',
                'rate_limit' => ApiRateLimit::AuthenticatedStandard,
            ],
            [
                'method' => 'POST',
                'uri' => 'api/v1/tenant/integration-tokens',
                'rate_limit' => ApiRateLimit::CteIntegrationTokenAdministration,
            ],
            [
                'method' => 'POST',
                'uri' => 'api/v1/tenant/integration-tokens/{token}/revoke',
                'rate_limit' => ApiRateLimit::CteIntegrationTokenAdministration,
            ],
        ];

        foreach ($expectedRoutes as $expected) {
            $route = collect(Route::getRoutes())->first(
                fn ($route): bool => $route->uri() === $expected['uri']
                    && in_array($expected['method'], $route->methods(), true),
            );

            $this->assertNotNull($route, "{$expected['method']} /{$expected['uri']} não registrada.");

            $middleware = $route->gatherMiddleware();
            $this->assertContains(
                ThrottleRequests::using($expected['rate_limit']),
                $middleware,
                "{$expected['method']} /{$expected['uri']} sem limiter esperado.",
            );
            $this->assertContains(
                EnsureRecentPasswordConfirmation::class,
                $middleware,
                "{$expected['method']} /{$expected['uri']} sem confirmação recente de senha.",
            );
        }
    }

    private function integrationRequest(string $token, string $ip): Request
    {
        return Request::create('/api/v1/integrations/cte/push', 'POST', server: [
            'REMOTE_ADDR' => $ip,
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
    }
}
