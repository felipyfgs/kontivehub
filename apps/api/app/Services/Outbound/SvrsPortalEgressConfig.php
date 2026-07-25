<?php

namespace App\Services\Outbound;

use InvalidArgumentException;
use RuntimeException;

/**
 * Configuração tipada do governador de egress SVRS.
 * Sem override por request de API.
 */
final class SvrsPortalEgressConfig
{
    public function cohortId(): string
    {
        $id = trim((string) config('sefaz.svrs_portal_egress.cohort_id', 'default'));
        if ($id === '' || strlen($id) > 64) {
            throw new RuntimeException('SVRS_EGRESS_COHORT_ID inválido.');
        }

        return $id;
    }

    public function deploymentId(): string
    {
        return mb_substr(trim((string) config('sefaz.svrs_portal_egress.deployment_id', 'local')), 0, 64);
    }

    public function requireSharedCoordinator(): bool
    {
        return (bool) config('sefaz.svrs_portal_egress.require_shared_coordinator', true);
    }

    public function host(): string
    {
        $host = strtolower(trim((string) config('sefaz.svrs_portal_egress.host', 'dfe-portal.svrs.rs.gov.br')));
        $this->assertAllowedHost($host);

        return $host;
    }

    /**
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        $hosts = config('sefaz.svrs_portal_egress.allowed_hosts', ['dfe-portal.svrs.rs.gov.br']);
        if (! is_array($hosts) || $hosts === []) {
            throw new RuntimeException('Lista de hosts SVRS portal vazia.');
        }

        return array_values(array_map(
            static fn ($h) => strtolower(trim((string) $h)),
            $hosts
        ));
    }

    public function isHostAllowed(string $host): bool
    {
        return in_array(strtolower(trim($host)), $this->allowedHosts(), true);
    }

    public function assertAllowedHost(string $host): void
    {
        if (! $this->isHostAllowed($host)) {
            throw new InvalidArgumentException('Host SVRS portal não allowlisted.');
        }
    }

    public function maxInflightTransactions(): int
    {
        return max(1, (int) config('sefaz.svrs_portal_egress.max_inflight_transactions', 1));
    }

    public function exchangesPerDownload(): int
    {
        return max(1, min(4, (int) config('sefaz.svrs_portal_egress.exchanges_per_download', 2)));
    }

    public function minIntervalGlobalSeconds(): float
    {
        return max(0.0, (float) config('sefaz.svrs_portal_egress.min_interval_global_seconds', 120));
    }

    public function minIntervalRootSeconds(): float
    {
        return max(0.0, (float) config('sefaz.svrs_portal_egress.min_interval_root_seconds', 900));
    }

    public function maxExchangesPerHour(): int
    {
        return max(1, (int) config('sefaz.svrs_portal_egress.max_exchanges_per_hour', 10));
    }

    public function maxExchangesPerDay(): int
    {
        return max(1, (int) config('sefaz.svrs_portal_egress.max_exchanges_per_day', 50));
    }

    public function maxKeysPerRootPerDay(): int
    {
        return max(1, (int) config('sefaz.svrs_portal_egress.max_keys_per_root_per_day', 6));
    }

    public function maxKeysPerJob(): int
    {
        return max(1, (int) config('sefaz.svrs_portal_egress.max_keys_per_job', 1));
    }

    public function retryJitterRatio(): float
    {
        return max(0.0, min(0.5, (float) config('sefaz.svrs_portal_egress.retry_jitter_ratio', 0.1)));
    }

    /**
     * @return list<int>
     */
    public function blockCooldownLadderSeconds(): array
    {
        $raw = config('sefaz.svrs_portal_egress.block_cooldown_ladder_seconds', [86400, 172800, 345600, 604800]);
        if (! is_array($raw) || $raw === []) {
            return [86400, 172800, 345600, 604800];
        }

        return array_values(array_map(static fn ($v) => max(60, (int) $v), $raw));
    }

    public function lockTtlSeconds(): int
    {
        return max(30, (int) config('sefaz.svrs_portal_egress.lock_ttl_seconds', 180));
    }

    public function reservationTtlSeconds(): int
    {
        return max(30, (int) config('sefaz.svrs_portal_egress.reservation_ttl_seconds', 120));
    }

    /**
     * Qualquer canal portal (NF-e ou NFC-e) habilitado exige cohort_id.
     */
    public function anyPortalChannelEnabled(): bool
    {
        return (bool) config('sefaz.svrs_nfce_xml.retrieval_enabled', false)
            || (bool) config('sefaz.svrs_nfe55_xml.retrieval_enabled', false);
    }
}
