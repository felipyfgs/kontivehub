<?php

namespace App\Services\Integra;

use App\Enums\SerproEnvironment;
use App\Models\Office;
use App\Services\Serpro\SerproReadinessService;

/**
 * Fachada tenant-safe para readiness SERPRO do escritório.
 * Controllers tenant usam esta classe (não SerproReadinessService direto).
 */
final class TenantIntegraReadinessService
{
    public function __construct(
        private readonly SerproReadinessService $readiness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forOffice(Office $office, ?SerproEnvironment $environment = null): array
    {
        $result = $this->readiness->evaluateOffice($office, $environment, persist: false);

        // Não vazar orçamento global nem readiness de outros tenants embutidos em detalhe sensível
        if (isset($result['global']) && is_array($result['global'])) {
            $global = $result['global'];
            unset($global['issues']); // pode conter detalhes de plataforma
            $result['global'] = [
                'result' => $global['result'] ?? null,
                'highest_gate' => $global['highest_gate'] ?? null,
                'kill_switch_active' => (bool) data_get($global, 'summary.kill_switch.global.active', data_get($global, 'summary.kill_switch')),
                'billable_egress_allowed' => (bool) data_get($global, 'summary.billable_egress_allowed', false),
                'live_evidence' => (bool) data_get($global, 'summary.live_evidence', false),
            ];
        }

        return $result;
    }
}
