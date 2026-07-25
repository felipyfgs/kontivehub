<?php

declare(strict_types=1);

namespace App\Services\FgtsDigital;

final class FgtsDigitalCaptchaConfig
{
    private const NOPECHA_TOKEN_ENDPOINT = 'https://api.nopecha.com/token/';

    /** @return array{code:string,message:string}|null */
    public function blocker(): ?array
    {
        $driver = (string) config('fgts_digital.captcha.driver', 'disabled');
        if (! in_array($driver, ['disabled', 'nopecha'], true)) {
            return $this->blocked('CAPTCHA_DRIVER_INVALID', 'Driver de CAPTCHA do FGTS Digital inválido.');
        }
        if ($driver === 'disabled') {
            return null;
        }

        if (! hash_equals(self::NOPECHA_TOKEN_ENDPOINT, (string) config('fgts_digital.captcha.endpoint'))) {
            return $this->blocked('CAPTCHA_ENDPOINT_NOT_ALLOWED', 'Endpoint do solver de CAPTCHA não permitido.');
        }
        if (trim((string) config('fgts_digital.captcha.api_key')) === '') {
            return $this->blocked('CAPTCHA_API_KEY_MISSING', 'Chave do solver de CAPTCHA não configurada.');
        }
        $proxyUrl = trim((string) config('fgts_digital.captcha.proxy_url'));
        if ($proxyUrl !== '' && ! $this->validProxyUrl($proxyUrl)) {
            return $this->blocked('CAPTCHA_PROXY_INVALID', 'Proxy opcional do solver de CAPTCHA é inválido.');
        }

        $attempts = (int) config('fgts_digital.captcha.max_attempts', 1);
        $creditsPerAttempt = (int) config('fgts_digital.captcha.credits_per_attempt', 5);
        $budget = (int) config('fgts_digital.captcha.max_credits_per_run', 5);
        $timeout = (int) config('fgts_digital.captcha.timeout_seconds', 180);
        $poll = (int) config('fgts_digital.captcha.poll_interval_milliseconds', 1_000);
        if ($attempts < 1 || $attempts > 2 || $creditsPerAttempt !== 5 || $budget < ($attempts * $creditsPerAttempt)) {
            return $this->blocked('CAPTCHA_BUDGET_INVALID', 'Limites de tentativas/créditos do solver são inválidos.');
        }
        if ($timeout < 10 || $timeout > 300 || $poll < 250 || $poll > 10_000) {
            return $this->blocked('CAPTCHA_TIMEOUT_INVALID', 'Timeout ou intervalo do solver é inválido.');
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function privateTransportConfig(): array
    {
        $driver = (string) config('fgts_digital.captcha.driver', 'disabled');
        if ($driver === 'disabled') {
            return ['driver' => 'disabled'];
        }

        return [
            'driver' => $driver,
            'endpoint' => (string) config('fgts_digital.captcha.endpoint'),
            'api_key' => (string) config('fgts_digital.captcha.api_key'),
            'proxy_url' => (string) config('fgts_digital.captcha.proxy_url'),
            'timeout_seconds' => (int) config('fgts_digital.captcha.timeout_seconds', 180),
            'poll_interval_milliseconds' => (int) config('fgts_digital.captcha.poll_interval_milliseconds', 1_000),
            'max_attempts' => (int) config('fgts_digital.captcha.max_attempts', 1),
        ];
    }

    /** @return array{driver:string,proxy_configured:bool,fail_closed:bool} */
    public function publicSummary(): array
    {
        return [
            'driver' => (string) config('fgts_digital.captcha.driver', 'disabled'),
            'proxy_configured' => $this->validProxyUrl((string) config('fgts_digital.captcha.proxy_url')),
            'fail_closed' => true,
        ];
    }

    private function validProxyUrl(string $proxyUrl): bool
    {
        if ($proxyUrl === '' || preg_match('/[\r\n]/', $proxyUrl) === 1) {
            return false;
        }
        $parts = parse_url($proxyUrl);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https', 'socks4', 'socks5'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || (int) ($parts['port'] ?? 0) < 1
            || (int) ($parts['port'] ?? 0) > 65_535) {
            return false;
        }

        return isset($parts['user']) === isset($parts['pass']);
    }

    /** @return array{code:string,message:string} */
    private function blocked(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
