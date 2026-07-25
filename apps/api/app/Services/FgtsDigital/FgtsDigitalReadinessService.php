<?php

namespace App\Services\FgtsDigital;

use App\Models\Client;
use App\Models\Office;

final class FgtsDigitalReadinessService
{
    public function __construct(
        private readonly FgtsDigitalCredentialResolver $credentials,
        private readonly FgtsDigitalSessionStore $sessions,
        private readonly FgtsDigitalCaptchaConfig $captchaConfig,
    ) {}

    /** @return array<string, mixed> */
    public function check(Office $office, Client $client): array
    {
        $driver = (string) config('fgts_digital.driver', 'disabled');
        $blockers = [];
        if (! in_array($driver, ['disabled', 'fixture', 'portal_browser'], true)) {
            $blockers[] = ['code' => 'FGTS_DIGITAL_DRIVER_INVALID', 'message' => 'Driver FGTS Digital inválido.'];
        } elseif ($driver === 'disabled') {
            $blockers[] = ['code' => 'FGTS_DIGITAL_DISABLED', 'message' => 'Portal FGTS Digital desabilitado.'];
        }
        if ((bool) config('fgts_digital.kill_switch', false)) {
            $blockers[] = ['code' => 'FGTS_DIGITAL_KILL_SWITCH', 'message' => 'Kill switch do FGTS Digital ativo.'];
        }
        if ($driver === 'portal_browser' && ! (bool) config('fgts_digital.egress_enabled', false)) {
            $blockers[] = ['code' => 'FGTS_DIGITAL_EGRESS_DISABLED', 'message' => 'Egress do portal desabilitado.'];
        }
        if ($driver === 'portal_browser') {
            $blockers = [...$blockers, ...$this->portalConfigurationBlockers()];
            $python = (string) config('fgts_digital.runtime.executable');
            $worker = (string) config('fgts_digital.runtime.worker');
            if (! is_executable($python) || ! is_readable($worker)) {
                $blockers[] = ['code' => 'FGTS_DIGITAL_RUNTIME_UNAVAILABLE', 'message' => 'Runtime do navegador indisponível.'];
            }
            if (($captchaBlocker = $this->captchaConfig->blocker()) !== null) {
                $blockers[] = $captchaBlocker;
            }
        }

        $credential = $blockers === []
            ? $this->credentials->resolve($office, $client, includeMaterial: false)
            : null;
        if ($blockers === [] && $credential === null) {
            $blockers[] = ['code' => 'FGTS_DIGITAL_CREDENTIAL_MISSING', 'message' => 'A1 do cliente ou procuração ativa não encontrada.'];
        }
        $session = $credential === null ? null : $this->sessions->latest(
            (int) $office->id,
            (int) $client->id,
            $credential['fingerprint'],
            $credential['profile_type'],
            FgtsDigitalCredentialResolver::identifierHash((string) $client->root_cnpj),
        );

        $readyForRead = $blockers === [];

        return [
            'driver' => $driver,
            'ready_for_read' => $readyForRead,
            'ready_for_mutation' => $readyForRead && (bool) config('fgts_digital.mutations_enabled', false),
            'mutations_enabled' => (bool) config('fgts_digital.mutations_enabled', false),
            'credential_source' => $credential['source']->value ?? null,
            'has_authorized_session' => $session !== null,
            'session' => $session?->toPublicArray(),
            'human_challenge_possible' => $driver === 'portal_browser' && $session === null,
            'captcha' => $this->captchaConfig->publicSummary(),
            'blockers' => $blockers,
            'supports_pdf_download' => true,
            'supports_pix_payment' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function coverage(): array
    {
        return [
            'source' => 'FGTS_DIGITAL_PORTAL',
            'driver' => (string) config('fgts_digital.driver', 'disabled'),
            'official_portal' => (string) config('fgts_digital.portal.app_url'),
            'capabilities' => [
                'query_guides' => true,
                'query_payment' => true,
                'download_pdf' => true,
                'quick_guides' => ['MONTHLY', 'TERMINATION', 'CONSIGNMENT', 'MIXED'],
                'parameterized_preview' => true,
                'emit_after_explicit_authorization' => true,
                'pix_payment' => false,
            ],
            'human_challenge_policy' => (string) config('fgts_digital.captcha.driver', 'disabled') === 'nopecha'
                ? 'SOLVE_HCAPTCHA_OR_PAUSE'
                : 'PAUSE_AND_IMPORT_AUTHORIZED_SESSION',
            'fail_closed' => true,
            'portal_manifest_version' => '2026-07-22.1',
            'scheduler' => [
                'enabled' => (bool) config('fgts_digital.scheduler.enabled', false),
                'emissions_enabled' => (bool) config('fgts_digital.scheduler.emissions_enabled', false),
                'max_amount_cents' => (int) config('fgts_digital.scheduler.max_amount_cents', 0),
            ],
        ];
    }

    /** @return list<array{code:string,message:string}> */
    private function portalConfigurationBlockers(): array
    {
        $blockers = [];
        $suffixes = array_map('strval', (array) config('fgts_digital.portal.allowed_host_suffixes', []));
        foreach (['login_url', 'app_url'] as $key) {
            $url = (string) config('fgts_digital.portal.'.$key, '');
            $host = parse_url($url, PHP_URL_HOST);
            $scheme = parse_url($url, PHP_URL_SCHEME);
            $allowed = is_string($host) && $scheme === 'https' && collect($suffixes)->contains(
                fn (string $suffix): bool => strtolower($host) === ltrim(strtolower($suffix), '.')
                    || str_ends_with(strtolower($host), strtolower($suffix)),
            );
            if (! $allowed) {
                $blockers[] = ['code' => 'FGTS_DIGITAL_PORTAL_HOST_INVALID', 'message' => 'Host do portal FGTS Digital fora da allowlist.'];
                break;
            }
        }
        $timeout = (int) config('fgts_digital.runtime.timeout_seconds', 0);
        $maxOutput = (int) config('fgts_digital.runtime.max_output_bytes', 0);
        if ($timeout < 1 || $timeout > 600 || $maxOutput < 1024 || $maxOutput > 33_554_432) {
            $blockers[] = ['code' => 'FGTS_DIGITAL_RUNTIME_CONFIG_INVALID', 'message' => 'Limites do runtime RPA são inválidos.'];
        }
        if ((bool) config('fgts_digital.mutations_enabled', false)
            && ! (bool) config('fgts_digital.egress_enabled', false)) {
            $blockers[] = ['code' => 'FGTS_DIGITAL_MUTATION_CONFIG_INVALID', 'message' => 'Mutações exigem egress do portal habilitado.'];
        }

        return $blockers;
    }
}
