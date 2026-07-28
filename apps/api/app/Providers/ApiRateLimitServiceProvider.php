<?php

namespace App\Providers;

use App\Enums\ApiRateLimit;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class ApiRateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for(
            ApiRateLimit::InternalCommunicationGateway,
            fn (Request $request): Limit => $this->perMinuteForIp($request, 600),
        );
        RateLimiter::for(
            ApiRateLimit::CteEmitterPush,
            fn (Request $request): array => $this->perMinuteForIntegrationToken(
                $request,
                (int) config('sefaz.cte_emitter_push.rate_limit_per_minute', 30),
                (int) config('sefaz.cte_emitter_push.ip_rate_limit_per_minute', 30),
            ),
        );
        RateLimiter::for(
            ApiRateLimit::PublicActivation,
            fn (Request $request): Limit => $this->perMinuteForIp($request, 20),
        );
        RateLimiter::for(
            ApiRateLimit::PublicOnboardingStatus,
            fn (Request $request): Limit => $this->perMinuteForIp($request, 20),
        );
        RateLimiter::for(
            ApiRateLimit::PublicOnboardingCompletion,
            fn (Request $request): Limit => $this->perMinuteForIp($request, 5),
        );
        RateLimiter::for(
            ApiRateLimit::AuthenticatedModerate,
            fn (Request $request): Limit => $this->perMinuteForUserOrIp($request, 30),
        );
        RateLimiter::for(
            ApiRateLimit::AuthenticatedStandard,
            fn (Request $request): Limit => $this->perMinuteForUserOrIp($request, 20),
        );
        RateLimiter::for(
            ApiRateLimit::AuthenticatedSensitive,
            fn (Request $request): Limit => $this->perMinuteForUserOrIp($request, 10),
        );
        RateLimiter::for(
            ApiRateLimit::AuthenticatedCritical,
            fn (Request $request): Limit => $this->perMinuteForUserOrIp($request, 5),
        );
        RateLimiter::for(
            ApiRateLimit::CommunicationMessageSend,
            fn (Request $request): Limit => $this->perMinuteForUserOrIp($request, 120),
        );
        RateLimiter::for(
            ApiRateLimit::AssistantAccess,
            fn (Request $request): Limit => $this->perMinuteForUserOrIp($request, 60),
        );
        RateLimiter::for(
            ApiRateLimit::AssistantChat,
            fn (Request $request): Limit => $this->perMinuteForUserOrIp($request, 30),
        );
        RateLimiter::for(
            ApiRateLimit::CteIntegrationTokenAdministration,
            fn (Request $request): Limit => $this->perMinuteForUserOrIp(
                $request,
                (int) config('sefaz.cte_emitter_push.admin_token_rate_limit_per_minute', 10),
            ),
        );
    }

    private function perMinuteForIp(Request $request, int $maxAttempts): Limit
    {
        return Limit::perMinute($maxAttempts)->by('ip:'.$request->ip());
    }

    private function perMinuteForUserOrIp(Request $request, int $maxAttempts): Limit
    {
        $identifier = $request->user()?->getAuthIdentifier();
        $key = $identifier === null
            ? 'ip:'.$request->ip()
            : 'user:'.$identifier;

        return Limit::perMinute($maxAttempts)->by($key);
    }

    /**
     * @return list<Limit>
     */
    private function perMinuteForIntegrationToken(
        Request $request,
        int $tokenMaxAttempts,
        int $ipMaxAttempts,
    ): array {
        $bearer = trim((string) $request->bearerToken());
        $tokenKey = $bearer === ''
            ? 'missing:'.$request->ip()
            : hash_hmac('sha256', $bearer, $this->integrationTokenDigestKey());

        return [
            Limit::perMinute(max(1, $tokenMaxAttempts))
                ->by('integration-token:'.$tokenKey),
            Limit::perMinute(max(1, $ipMaxAttempts))
                ->by('ip:'.$request->ip()),
        ];
    }

    private function integrationTokenDigestKey(): string
    {
        $key = trim((string) config('sefaz.cte_emitter_push.rate_limit_digest_key'));
        $key = $key !== '' ? $key : trim((string) config('app.key'));

        if ($key === '') {
            throw new LogicException('Chave do digest do limiter de integração não configurada.');
        }

        return $key;
    }
}
