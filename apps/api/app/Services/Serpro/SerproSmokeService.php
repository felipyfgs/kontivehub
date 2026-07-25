<?php

namespace App\Services\Serpro;

use App\Contracts\SerproContractAuthenticator;
use App\Enums\SerproEnvironment;
use App\Enums\SerproReadinessGate;
use App\Models\SerproContract;
use App\Services\Audit\AuditLogger;
use RuntimeException;
use Throwable;

/**
 * Smoke produtivo opt-in: TLS handshake e OAuth mTLS somente.
 *
 * NUNCA chama /Consultar, /Emitir, /Declarar ou qualquer rota de negócio.
 * NUNCA roda em CI. Default OFF (SERPRO_SMOKE_ENABLED=false).
 * NUNCA retorna tokens, Consumer Secret, PFX ou corpo de resposta bruto.
 */
final class SerproSmokeService
{
    public const CONFIRM_PHRASE = 'I_UNDERSTAND_LIVE_SERPRO';

    /** @var list<string> */
    private const FORBIDDEN_PATH_FRAGMENTS = [
        '/Consultar',
        '/Emitir',
        '/Declarar',
        'Consultar',
        'Emitir',
        'Declarar',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SerproContractService $contracts,
        private readonly SerproReadinessPromotionService $promotion,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('serpro.smoke.enabled', false);
    }

    public function isCiEnvironment(): bool
    {
        if (filter_var(getenv('CI') ?: false, FILTER_VALIDATE_BOOL)) {
            return true;
        }
        if (filter_var(env('CI', false), FILTER_VALIDATE_BOOL)) {
            return true;
        }
        if (filter_var(env('GITHUB_ACTIONS', false), FILTER_VALIDATE_BOOL)) {
            return true;
        }
        if (filter_var(env('GITLAB_CI', false), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        return false;
    }

    /**
     * Status sanitizado (offline — sem rede).
     *
     * @return array<string, mixed>
     */
    public function status(?SerproEnvironment $environment = null): array
    {
        $environment ??= $this->defaultEnvironment();
        $contract = $this->contracts->activeFor($environment);

        return [
            'smoke_enabled' => $this->isEnabled(),
            'smoke_status' => (string) config('serpro.smoke.status', 'PENDING_OPS'),
            'ci_blocked' => $this->isCiEnvironment(),
            'confirm_phrase_required' => self::CONFIRM_PHRASE,
            'live_modes' => ['tls', 'oauth'],
            'forbidden_routes' => ['/Consultar', '/Emitir', '/Declarar'],
            'free_smoke_ladder' => [
                'Termo local',
                '/Apoiar (Autentica Procurador)',
                'powers/offline evidence',
                '/Monitorar (limites oficiais)',
                'FREE_SMOKE_OK (sem canário faturável)',
            ],
            'environment' => $environment->value,
            'active_contract_id' => $contract?->id,
            'active_contract_fingerprint' => $contract?->fingerprint_sha256
                ? substr((string) $contract->fingerprint_sha256, 0, 16).'…'
                : null,
            'oauth_token_url_host' => $this->hostFromUrl((string) config('serpro.oauth.token_url', '')),
            'api_base_url_host' => $this->hostFromUrl((string) config('serpro.api.base_url', '')),
            'note' => 'Live TLS/OAuth exigem SERPRO_SMOKE_ENABLED=true e --confirm='.self::CONFIRM_PHRASE,
        ];
    }

    /**
     * Handshake TLS + cadeia no host oficial (sem mTLS, sem OAuth, sem rota fiscal).
     *
     * @return array<string, mixed>
     */
    public function tlsHandshake(
        bool $confirmLive,
        ?string $url = null,
        bool $recordReadiness = false,
        ?int $actorUserId = null,
        ?SerproEnvironment $environment = null,
    ): array {
        $this->assertLiveAllowed($confirmLive);

        $url ??= (string) config('serpro.oauth.token_url');
        $this->assertUrlSafeForSmoke($url);

        $host = $this->hostFromUrl($url);
        $port = $this->portFromUrl($url);
        if ($host === null || $host === '') {
            throw new RuntimeException('URL de smoke TLS inválida (host ausente).');
        }

        $started = hrtime(true);
        $peerFingerprint = null;
        $tlsVersion = null;
        $chainCount = 0;
        $error = null;

        try {
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'capture_peer_cert_chain' => true,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'peer_name' => $host,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                ],
            ]);

            $errno = 0;
            $errstr = '';
            $fp = @stream_socket_client(
                'ssl://'.$host.':'.$port,
                $errno,
                $errstr,
                max(3, (int) config('serpro.oauth.connect_timeout_seconds', 10)),
                STREAM_CLIENT_CONNECT,
                $context,
            );

            if ($fp === false) {
                throw new RuntimeException('Falha TLS: '.$this->sanitizeMessage($errstr !== '' ? $errstr : 'errno='.$errno));
            }

            $params = stream_context_get_params($fp);
            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            $chain = $params['options']['ssl']['peer_certificate_chain'] ?? [];
            $chainCount = is_array($chain) ? count($chain) : 0;

            if (is_resource($cert) || (is_object($cert) && $cert instanceof \OpenSSLCertificate)) {
                $parsed = openssl_x509_parse($cert);
                if (is_array($parsed)) {
                    $raw = openssl_x509_export($cert, $out) ? $out : '';
                    if ($raw !== '') {
                        $der = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $raw) ?? '';
                        $bin = base64_decode($der, true);
                        if (is_string($bin) && $bin !== '') {
                            $peerFingerprint = hash('sha256', $bin);
                        }
                    }
                }
            }

            $meta = stream_get_meta_data($fp);
            $crypto = $meta['crypto'] ?? null;
            if (is_array($crypto)) {
                $tlsVersion = (string) ($crypto['protocol'] ?? $crypto['version'] ?? 'TLS');
            }

            fclose($fp);
        } catch (Throwable $e) {
            $error = $this->sanitizeMessage($e->getMessage());
        }

        $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $ok = $error === null && $peerFingerprint !== null;

        $result = [
            'mode' => 'tls',
            'ok' => $ok,
            'host' => $host,
            'port' => $port,
            'tls_version' => $tlsVersion,
            'chain_depth' => $chainCount,
            'peer_cert_sha256' => $peerFingerprint !== null ? substr($peerFingerprint, 0, 16).'…' : null,
            'latency_ms' => $latencyMs,
            'error' => $error,
            'billable_routes_called' => false,
            'recorded_readiness' => false,
        ];

        $this->audit->record('serpro.smoke.tls', $ok ? 'SUCCESS' : 'FAILED', null, [
            'host' => $host,
            'ok' => $ok,
            'chain_depth' => $chainCount,
            'latency_ms' => $latencyMs,
        ], $actorUserId, null);

        if ($ok && $recordReadiness) {
            $this->promotion->recordLiveGate(
                SerproReadinessGate::TlsOk,
                'PASS',
                'Handshake TLS+cadeia OK no host oficial (smoke).',
                environment: $environment,
                actorUserId: $actorUserId,
                live: true,
                fingerprint: $peerFingerprint,
                metadata: [
                    'host' => $host,
                    'chain_depth' => $chainCount,
                    'latency_ms' => $latencyMs,
                ],
                trigger: 'SMOKE_TLS',
            );
            $result['recorded_readiness'] = true;
        }

        return $result;
    }

    /**
     * OAuth mTLS real: emite par Bearer+JWT, valida expiração, NÃO chama rota de negócio.
     * Tokens NUNCA saem no retorno — só metadados sanitizados.
     *
     * @return array<string, mixed>
     */
    public function oauthMtls(
        bool $confirmLive,
        ?SerproContract $contract = null,
        ?SerproEnvironment $environment = null,
        bool $recordReadiness = false,
        ?int $actorUserId = null,
        ?SerproContractAuthenticator $authenticator = null,
    ): array {
        $this->assertLiveAllowed($confirmLive);

        $environment ??= $this->defaultEnvironment();
        $contract ??= $this->contracts->activeFor($environment);
        if ($contract === null) {
            throw new RuntimeException('Nenhum contrato ACTIVE para OAuth smoke; informe contrato.');
        }

        $tokenUrl = (string) config('serpro.oauth.token_url');
        $this->assertUrlSafeForSmoke($tokenUrl);

        $authenticator ??= app(SerproContractAuthenticator::class);
        $started = hrtime(true);
        $error = null;
        $sanitizedToken = null;

        try {
            // Invalida cache para forçar round-trip real quando possível.
            $authenticator->invalidate($contract);
            $token = $authenticator->authenticate($contract);
            $token->assertComplete();
            if ($token->isExpired(0)) {
                throw new RuntimeException('Token OAuth retornou já expirado.');
            }
            $sanitizedToken = $token->toSanitizedArray();
            // Descartar material sensível da memória local o quanto antes.
            unset($token);
        } catch (Throwable $e) {
            $error = $this->sanitizeMessage($e->getMessage());
        }

        $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $ok = $error === null && is_array($sanitizedToken);

        $result = [
            'mode' => 'oauth',
            'ok' => $ok,
            'contract_id' => $contract->id,
            'environment' => $environment->value,
            'oauth_host' => $this->hostFromUrl($tokenUrl),
            'token' => $sanitizedToken,
            'latency_ms' => $latencyMs,
            'error' => $error,
            'billable_routes_called' => false,
            'business_routes_called' => false,
            'recorded_readiness' => false,
            'note' => 'OAuth smoke encerra sem rota de negócio.',
        ];

        $this->audit->record('serpro.smoke.oauth', $ok ? 'SUCCESS' : 'FAILED', $contract, [
            'ok' => $ok,
            'latency_ms' => $latencyMs,
            'has_access_token' => (bool) ($sanitizedToken['has_access_token'] ?? false),
            'has_jwt_token' => (bool) ($sanitizedToken['has_jwt_token'] ?? false),
        ], $actorUserId, null);

        if ($ok && $recordReadiness) {
            $this->promotion->recordLiveGate(
                SerproReadinessGate::OauthOk,
                'PASS',
                'OAuth mTLS OK (Bearer+JWT com expiração; sem rota de negócio).',
                environment: $environment,
                actorUserId: $actorUserId,
                live: true,
                fingerprint: $contract->fingerprint_sha256,
                metadata: [
                    'contract_id' => $contract->id,
                    'latency_ms' => $latencyMs,
                    'has_jwt' => true,
                ],
                trigger: 'SMOKE_OAUTH',
            );
            $result['recorded_readiness'] = true;
        }

        return $result;
    }

    /**
     * Checklist offline de deploy limpo (flags OFF, demo, kill switch, smoke off).
     *
     * @return array{ok: bool, checks: list<array{id: string, ok: bool, detail: string}>}
     */
    public function cleanDeployChecklist(): array
    {
        $checks = [];
        $add = function (string $id, bool $ok, string $detail) use (&$checks): void {
            $checks[] = ['id' => $id, 'ok' => $ok, 'detail' => $detail];
        };

        $kill = (bool) config('serpro.kill_switch', false);
        // Em deploy limpo pré-smoke, kill switch pode estar ON (contenção) — aceitável.
        $add('kill_switch_readable', true, $kill ? 'Kill switch env ON (contenção).' : 'Kill switch env OFF.');

        $capabilities = config('serpro.capabilities', []);
        $realDrivers = [];
        if (is_array($capabilities)) {
            foreach ($capabilities as $cap => $driver) {
                if (is_string($driver) && strtolower($driver) === 'real') {
                    $realDrivers[] = (string) $cap;
                }
            }
        }
        $add(
            'drivers_not_real',
            $realDrivers === [],
            $realDrivers === []
                ? 'Nenhum driver real habilitado.'
                : 'Drivers real ativos: '.implode(',', $realDrivers).' — inesperado em deploy limpo pré-go-live.',
        );

        $smokeOn = $this->isEnabled();
        $add(
            'smoke_default_off',
            ! $smokeOn,
            $smokeOn
                ? 'SERPRO_SMOKE_ENABLED=true — só manter durante janela de smoke controlada.'
                : 'SERPRO_SMOKE_ENABLED=false (default seguro).',
        );

        $add(
            'ci_not_running_live',
            ! $this->isCiEnvironment() || ! $smokeOn,
            $this->isCiEnvironment()
                ? 'Ambiente CI detectado — live smoke bloqueado pelo serviço.'
                : 'Fora de CI.',
        );

        $featuresKill = (bool) config('features.kill_switch', false);
        $add(
            'features_kill_switch',
            true,
            $featuresKill ? 'FEATURES_KILL_SWITCH ON.' : 'FEATURES_KILL_SWITCH OFF.',
        );

        $mutatingKill = (bool) config('features.mutating.kill_switch', false)
            || (bool) config('fiscal_mutations.kill_switch', false);
        $add(
            'mutations_contained',
            true,
            $mutatingKill
                ? 'Kill switch de mutações ativo.'
                : 'Verificar flags mutantes OFF manualmente (default OFF em features.php).',
        );

        $ok = true;
        foreach ($checks as $c) {
            if (! $c['ok']) {
                $ok = false;
                break;
            }
        }

        return ['ok' => $ok, 'checks' => $checks];
    }

    private function assertLiveAllowed(bool $confirmLive): void
    {
        if ($this->isCiEnvironment()) {
            throw new RuntimeException('serpro:smoke live é proibido em CI (CI/GITHUB_ACTIONS).');
        }

        if (! $this->isEnabled()) {
            throw new RuntimeException(
                'SERPRO_SMOKE_ENABLED=false. Habilite somente em janela operacional controlada (nunca em CI).'
            );
        }

        if (! $confirmLive) {
            throw new RuntimeException(
                'Confirmação ausente. Passe --confirm='.self::CONFIRM_PHRASE
            );
        }
    }

    public function confirmMatches(?string $phrase): bool
    {
        return is_string($phrase)
            && hash_equals(self::CONFIRM_PHRASE, trim($phrase));
    }

    private function assertUrlSafeForSmoke(string $url): void
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $full = $url.$path;
        foreach (self::FORBIDDEN_PATH_FRAGMENTS as $frag) {
            if (str_contains($full, $frag) && ! str_contains($path, 'authenticate')) {
                // authenticate path is fine; block business routes
                if (in_array($frag, ['/Consultar', '/Emitir', '/Declarar', 'Consultar', 'Emitir', 'Declarar'], true)
                    && (str_contains($path, $frag) || str_contains($url, $frag))
                ) {
                    throw new RuntimeException('URL de smoke aponta para rota de negócio proibida: '.$frag);
                }
            }
        }

        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'https') {
            throw new RuntimeException('Smoke exige HTTPS.');
        }
    }

    private function hostFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }

    private function portFromUrl(string $url): int
    {
        $port = parse_url($url, PHP_URL_PORT);
        if (is_int($port) && $port > 0) {
            return $port;
        }

        return 443;
    }

    private function defaultEnvironment(): SerproEnvironment
    {
        return SerproEnvironment::tryFrom(strtoupper((string) config('serpro.default_environment', 'TRIAL')))
            ?? SerproEnvironment::Trial;
    }

    private function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/Basic\s+\S+/i', 'Basic [redacted]', $message) ?? $message;
        $message = preg_replace('/consumer[_-]?secret[=:]\s*\S+/i', 'consumer_secret=[redacted]', $message) ?? $message;

        return mb_substr($message, 0, 400);
    }
}
