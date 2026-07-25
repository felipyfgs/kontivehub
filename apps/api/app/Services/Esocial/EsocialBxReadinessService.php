<?php

namespace App\Services\Esocial;

use App\DTO\Esocial\EsocialBxReadiness;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalOperationClass;
use App\Exceptions\EsocialBxException;
use App\Models\Client;
use App\Models\Office;
use App\Services\Fiscal\Availability\FiscalModuleAvailabilityService;
use Carbon\CarbonImmutable;

final class EsocialBxReadinessService
{
    public function __construct(
        private readonly EsocialBxCredentialResolver $credentials,
        private readonly EsocialBxAccessGuard $guard,
        private readonly EsocialBxConfig $config,
        private readonly FiscalModuleAvailabilityService $availability,
    ) {}

    public function check(Office $office, Client $client, ?CarbonImmutable $now = null): EsocialBxReadiness
    {
        $now = ($now ?? CarbonImmutable::now($this->config->timezone()))
            ->setTimezone($this->config->timezone());
        $driver = (string) config('fgts_esocial.driver', 'disabled');
        $environment = (string) config('fgts_esocial.environment', 'restricted');
        $limit = max(1, min(10, (int) config('fgts_esocial.official_bx.daily_access_limit', 10)));
        $blockers = $this->config->blockers();
        $sameTenant = (int) $client->office_id === (int) $office->id;

        if ($driver === 'disabled') {
            $blockers[] = ['code' => 'ESOCIAL_BX_DISABLED', 'message' => 'Provider eSocial BX desabilitado.'];
        }
        if ((bool) config('fgts_esocial.kill_switch', false)) {
            $blockers[] = ['code' => 'ESOCIAL_BX_KILL_SWITCH', 'message' => 'Kill switch do eSocial BX ativo.'];
        }
        if (! $sameTenant) {
            $blockers[] = ['code' => 'ESOCIAL_BX_CLIENT_NOT_FOUND', 'message' => 'Cliente indisponível no tenant atual.'];
        }

        if ($blockers === []) {
            $decision = $this->availability->resolve(
                FiscalControlModule::Fgts,
                $office,
                FiscalOperationClass::Read,
            );
            if (! $decision->allowed) {
                $blockers[] = [
                    'code' => 'ESOCIAL_BX_FEATURE_DISABLED',
                    'message' => 'Módulo FGTS indisponível para este office.',
                ];
            }
        }

        if ($blockers === []) {
            try {
                $this->guard->assertOperationalWindow($now);
            } catch (EsocialBxException $exception) {
                $blockers[] = [
                    'code' => $exception->stableCode,
                    'message' => 'Consultas oficiais ficam bloqueadas entre os dias 1 e 7.',
                ];
            }
        }

        $consumed = $sameTenant && $driver === 'official_bx' && in_array($environment, ['restricted', 'production'], true)
            ? $this->guard->consumedToday($client, $environment, $now)
            : 0;
        if ($consumed >= $limit) {
            $blockers[] = ['code' => 'ESOCIAL_BX_QUOTA_EXHAUSTED', 'message' => 'Cota local conservadora esgotada para hoje.'];
        }

        $credential = null;
        if ($blockers === []) {
            $credential = $this->credentials->active($office, $client);
            if ($credential === null) {
                $blockers[] = ['code' => 'ESOCIAL_BX_CREDENTIAL_MISSING', 'message' => 'Certificado A1 ativo não encontrado.'];
            } elseif ($credential->valid_to === null || $credential->valid_to->lessThanOrEqualTo($now)) {
                $blockers[] = ['code' => 'ESOCIAL_BX_CREDENTIAL_EXPIRED', 'message' => 'Certificado A1 expirado.'];
            } elseif ($credential->valid_from !== null && $credential->valid_from->isAfter($now)) {
                $blockers[] = ['code' => 'ESOCIAL_BX_CREDENTIAL_NOT_YET_VALID', 'message' => 'Certificado A1 ainda não está válido.'];
            } elseif (substr(preg_replace('/\D/', '', (string) $credential->holder_cnpj) ?? '', 0, 8)
                !== (string) $client->root_cnpj) {
                $blockers[] = ['code' => 'ESOCIAL_BX_CREDENTIAL_IDENTITY_MISMATCH', 'message' => 'Identidade do certificado diverge do empregador.'];
            }
        }

        return new EsocialBxReadiness(
            ready: $blockers === [],
            driver: $driver,
            environment: $environment,
            blockers: $blockers,
            dailyLimit: $limit,
            locallyConsumed: $consumed,
            locallyRemaining: max(0, $limit - $consumed),
            credentialFingerprint: $credential?->fingerprint_sha256,
            credentialExpiresAt: $credential?->valid_to?->toIso8601String(),
        );
    }
}
