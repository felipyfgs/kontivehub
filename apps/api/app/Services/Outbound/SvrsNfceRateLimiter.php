<?php

namespace App\Services\Outbound;

use App\Contracts\SvrsPortalEgressGovernor;
use App\Domain\Cnpj;
use App\DTO\Outbound\SvrsEgressReservation;
use App\DTO\Outbound\SvrsEgressReserveRequest;
use Illuminate\Support\Facades\Cache;

/**
 * Adapter de compatibilidade: delega ao SvrsPortalEgressGovernor compartilhado.
 * Defaults defensivos (120s/15min/1 chave) — não mais 5s/30s/20.
 *
 * Prefira passar {@see SvrsEgressReservation} explicitamente em release()
 * (retorno de acquire) em vez de estado mutável da instância.
 *
 * @deprecated Prefira injetar SvrsPortalEgressGovernor diretamente.
 */
final class SvrsNfceRateLimiter
{
    public function __construct(
        private readonly SvrsNfceConfig $config,
        private readonly ?SvrsPortalEgressGovernor $governor = null,
        private readonly ?SvrsPortalEgressConfig $egressConfig = null,
    ) {}

    /**
     * @return array{allowed: bool, retry_after_seconds: int, reason: ?string, reservation?: ?SvrsEgressReservation}
     */
    public function acquire(
        int $clientId,
        ?string $rootCnpj = null,
        int $officeId = 0,
        string $channel = 'nfce65',
        bool $isCanary = false,
    ): array {
        $governor = $this->governor ?? (app()->bound(SvrsPortalEgressGovernor::class)
            ? app(SvrsPortalEgressGovernor::class)
            : null);

        if ($governor === null) {
            return ['allowed' => false, 'retry_after_seconds' => 60, 'reason' => 'coordinator_unavailable'];
        }

        $egress = $this->egressConfig ?? app(SvrsPortalEgressConfig::class);
        $root = $this->normalizeRootInput($rootCnpj, $clientId);

        $channel = $channel === 'nfe55' ? 'nfe55' : 'nfce65';

        $result = $governor->reserve(new SvrsEgressReserveRequest(
            rootCnpj: $root,
            accessKeyMask: '****',
            channel: $channel,
            officeId: max(0, $officeId),
            exchangesNeeded: $egress->exchangesPerDownload(),
            isCanary: $isCanary,
        ));

        if (! $result->allowed || $result->reservation === null) {
            return [
                'allowed' => false,
                'retry_after_seconds' => $result->retryAfterSeconds,
                'reason' => $result->reason ?? 'denied',
            ];
        }

        return [
            'allowed' => true,
            'retry_after_seconds' => 0,
            'reason' => null,
            'reservation' => $result->reservation,
        ];
    }

    /**
     * Libera a reserva obtida em acquire(). Passe a reservation explicitamente
     * (retorno de acquire) — sem estado mutável no limiter.
     */
    public function release(?SvrsEgressReservation $reservation = null): void
    {
        if ($reservation === null) {
            return;
        }
        $governor = $this->governor ?? (app()->bound(SvrsPortalEgressGovernor::class)
            ? app(SvrsPortalEgressGovernor::class)
            : null);
        if ($governor !== null) {
            $governor->release($reservation, false);
        }
    }

    /** @internal testes — limpa contadores do governador na coorte atual */
    public function reset(): void
    {
        $cohort = app(SvrsPortalEgressConfig::class)->cohortId();
        $prefix = 'svrs.egress.'.$cohort.'.';
        foreach (['inflight', 'last_global', 'mutex'] as $suffix) {
            Cache::forget($prefix.$suffix);
        }
        // Janelas de hora/dia
        Cache::forget($prefix.'ex_h.'.gmdate('YmdH'));
        Cache::forget($prefix.'ex_d.'.gmdate('Ymd'));
    }

    /**
     * Alfanumérico maiúsculo (Cnpj::normalize); CNPJ 14 → raiz 8; fallback CLIENT{id}.
     */
    private function normalizeRootInput(?string $rootCnpj, int $clientId): string
    {
        if ($rootCnpj === null || $rootCnpj === '') {
            return 'CLIENT'.$clientId;
        }

        $clean = Cnpj::normalize($rootCnpj);
        if ($clean === '') {
            return 'CLIENT'.$clientId;
        }

        if (strlen($clean) === 14) {
            $parsed = Cnpj::tryParse($clean);

            return $parsed !== null ? $parsed->root() : substr($clean, 0, 8);
        }

        return $clean;
    }
}
