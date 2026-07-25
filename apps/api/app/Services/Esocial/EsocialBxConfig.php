<?php

declare(strict_types=1);

namespace App\Services\Esocial;

use App\Exceptions\EsocialBxException;
use DateTimeZone;

final class EsocialBxConfig
{
    private const ENDPOINTS = [
        'restricted' => [
            'identifiers' => 'https://webservices.producaorestrita.esocial.gov.br/servicos/empregador/dwlcirurgico/WsConsultarIdentificadoresEventos.svc',
            'downloads' => 'https://webservices.producaorestrita.esocial.gov.br/servicos/empregador/dwlcirurgico/WsSolicitarDownloadEventos.svc',
        ],
        'production' => [
            'identifiers' => 'https://webservices.download.esocial.gov.br/servicos/empregador/dwlcirurgico/WsConsultarIdentificadoresEventos.svc',
            'downloads' => 'https://webservices.download.esocial.gov.br/servicos/empregador/dwlcirurgico/WsSolicitarDownloadEventos.svc',
        ],
    ];

    /** @return list<array{code:string,message:string}> */
    public function blockers(): array
    {
        $blockers = [];
        $driver = (string) config('fgts_esocial.driver', 'disabled');
        $environment = (string) config('fgts_esocial.environment', 'restricted');

        if (! in_array($driver, ['disabled', 'official_bx'], true)) {
            $blockers[] = $this->blocked('ESOCIAL_BX_DRIVER_INVALID', 'Driver eSocial BX inválido.');
        }
        if (! array_key_exists($environment, self::ENDPOINTS)) {
            $blockers[] = $this->blocked('ESOCIAL_BX_ENVIRONMENT_INVALID', 'Ambiente eSocial BX inválido.');
        }
        if ($environment === 'production' && ! (bool) config('fgts_esocial.production_egress_enabled', false)) {
            $blockers[] = $this->blocked(
                'ESOCIAL_BX_PRODUCTION_EGRESS_DISABLED',
                'Egress de produção do eSocial BX desabilitado.',
            );
        }

        $timezone = (string) config('fgts_esocial.official_bx.timezone', 'America/Sao_Paulo');
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $blockers[] = $this->blocked('ESOCIAL_BX_TIMEZONE_INVALID', 'Timezone eSocial BX inválido.');
        }

        $limit = (int) config('fgts_esocial.official_bx.daily_access_limit', 10);
        $batch = (int) config('fgts_esocial.official_bx.batch_limit', 50);
        $lag = (int) config('fgts_esocial.official_bx.minimum_lag_minutes', 60);
        $interval = (int) config('fgts_esocial.official_bx.max_query_interval_days', 31);
        $connectTimeout = (int) config('fgts_esocial.official_bx.connect_timeout_seconds', 15);
        $timeout = (int) config('fgts_esocial.official_bx.timeout_seconds', 90);
        $lock = (int) config('fgts_esocial.official_bx.lock_seconds', 180);
        if ($limit < 1 || $limit > 10 || $batch < 1 || $batch > 50 || $lag < 60 || $interval < 1 || $interval > 31) {
            $blockers[] = $this->blocked('ESOCIAL_BX_LIMITS_INVALID', 'Limites oficiais do eSocial BX inválidos.');
        }
        if ($connectTimeout < 1 || $timeout < $connectTimeout || $timeout > 300 || $lock < ($timeout + 30)) {
            $blockers[] = $this->blocked('ESOCIAL_BX_TIMEOUTS_INVALID', 'Timeouts/lock do eSocial BX inválidos.');
        }

        $blockedDays = array_values(array_unique(array_map(
            'intval',
            (array) config('fgts_esocial.official_bx.blocked_days', range(1, 7)),
        )));
        if (array_diff(range(1, 7), $blockedDays) !== [] || array_diff($blockedDays, range(1, 31)) !== []) {
            $blockers[] = $this->blocked('ESOCIAL_BX_BLOCKED_DAYS_INVALID', 'Janela mensal bloqueada do eSocial BX inválida.');
        }

        foreach (self::ENDPOINTS as $candidateEnvironment => $kinds) {
            foreach ($kinds as $kind => $officialUrl) {
                if (! hash_equals(
                    $officialUrl,
                    (string) config("fgts_esocial.official_bx.endpoints.{$candidateEnvironment}.{$kind}"),
                )) {
                    $blockers[] = $this->blocked('ESOCIAL_BX_ENDPOINT_NOT_ALLOWED', 'Endpoint eSocial BX fora da allowlist.');
                    break 2;
                }
            }
        }

        return $blockers;
    }

    public function endpoint(string $environment, string $kind): string
    {
        $officialUrl = self::ENDPOINTS[$environment][$kind] ?? null;
        $configured = config("fgts_esocial.official_bx.endpoints.{$environment}.{$kind}");
        if (! is_string($officialUrl) || ! is_string($configured) || ! hash_equals($officialUrl, $configured)) {
            throw new EsocialBxException(
                'ESOCIAL_BX_ENDPOINT_NOT_ALLOWED',
                'Endpoint eSocial BX fora da allowlist.',
                blocked: true,
            );
        }

        return $configured;
    }

    public function assertAllowedEndpoint(string $endpoint): void
    {
        foreach (self::ENDPOINTS as $environment => $kinds) {
            foreach ($kinds as $kind => $officialUrl) {
                if (hash_equals($officialUrl, $endpoint)) {
                    $this->endpoint($environment, $kind);

                    return;
                }
            }
        }

        throw new EsocialBxException(
            'ESOCIAL_BX_ENDPOINT_NOT_ALLOWED',
            'Endpoint eSocial BX fora da allowlist.',
            blocked: true,
        );
    }

    public function timezone(): string
    {
        $timezone = (string) config('fgts_esocial.official_bx.timezone', 'America/Sao_Paulo');
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new EsocialBxException('ESOCIAL_BX_TIMEZONE_INVALID', 'Timezone eSocial BX inválido.', blocked: true);
        }

        return $timezone;
    }

    /** @return array{code:string,message:string} */
    private function blocked(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
