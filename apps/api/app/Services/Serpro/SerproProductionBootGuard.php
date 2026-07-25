<?php

namespace App\Services\Serpro;

use RuntimeException;

/**
 * Preflight de boot/readiness: rejeita doubles e endpoints inválidos em production.
 */
final class SerproProductionBootGuard
{
    /**
     * @return list<string> issues (vazio = ok)
     */
    public function inspect(): array
    {
        $issues = [];
        $isProduction = app()->environment('production');

        if (! $isProduction) {
            return $issues;
        }

        $drivers = config('serpro.capabilities', []);
        if (is_array($drivers)) {
            foreach ($drivers as $name => $driver) {
                if (strtolower((string) $driver) === 'simulated') {
                    $issues[] = "Driver simulated proibido em production: {$name}";
                }
            }
        }

        if (! (bool) config('serpro.api.verify_tls', true)) {
            $issues[] = 'TLS verification desabilitada (verify_tls=false) em production.';
        }

        $tokenUrl = (string) config('serpro.oauth.token_url', '');
        $parts = parse_url($tokenUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $canonicalHost = strtolower((string) config('serpro.oauth_canonical_host', 'autenticacao.sapi.serpro.gov.br'));
        $canonicalPath = (string) config('serpro.oauth_canonical_path', '/authenticate');
        if ($host !== $canonicalHost || rtrim($path, '/') !== rtrim($canonicalPath, '/')) {
            $issues[] = 'OAuth token_url fora do endpoint canônico /authenticate.';
        }

        $baseUrl = (string) config('serpro.api.base_url', '');
        $allowedHosts = [
            'gateway.apiserpro.serpro.gov.br',
        ];
        $apiHost = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?? ''));
        if ($apiHost === '' || ! in_array($apiHost, $allowedHosts, true)) {
            $issues[] = 'API base_url fora da allowlist de hosts SERPRO.';
        }

        return $issues;
    }

    /**
     * Lança se production estiver em estado inválido.
     */
    public function assertSafeOrFail(): void
    {
        $issues = $this->inspect();
        if ($issues !== []) {
            throw new RuntimeException(
                'SERPRO production boot guard falhou: '.implode(' | ', $issues)
            );
        }
    }
}
