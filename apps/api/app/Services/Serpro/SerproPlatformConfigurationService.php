<?php

namespace App\Services\Serpro;

use App\Enums\SerproCredentialVersionStatus;
use App\Enums\SerproEnvironment;
use App\Enums\TenantSerproOnboardingStatus;
use App\Models\SerproContract;
use App\Models\SerproCredentialVersion;
use App\Models\SerproRuntimeControl;
use App\Models\TenantSerproOnboardingState;

/**
 * Read model sanitizado da configuração global SERPRO por ambiente.
 * Nunca carrega ou devolve material do vault.
 */
final class SerproPlatformConfigurationService
{
    public function __construct(
        private readonly SerproContractService $contracts,
        private readonly SerproExternalGateService $externalGates,
        private readonly SerproKillSwitchService $killSwitch,
        private readonly SerproQuantityUsageLimitService $quantityLimits,
        private readonly SerproReadinessService $readiness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getConfiguration(SerproEnvironment $environment): array
    {
        $activeVersion = SerproCredentialVersion::query()
            ->where('environment', $environment->value)
            ->where('status', SerproCredentialVersionStatus::Active->value)
            ->orderByDesc('id')
            ->first();

        $pendingVersions = SerproCredentialVersion::query()
            ->where('environment', $environment->value)
            ->whereIn('status', [
                SerproCredentialVersionStatus::Pending->value,
                SerproCredentialVersionStatus::Verified->value,
            ])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map->toSanitizedArray()
            ->all();

        $history = SerproCredentialVersion::query()
            ->where('environment', $environment->value)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map->toSanitizedArray()
            ->all();

        $contract = $this->contracts->activeFor($environment)
            ?? SerproContract::query()
                ->where('environment', $environment->value)
                ->orderByDesc('id')
                ->first();

        $quantity = $this->quantityLimits->getOrDefault($environment);
        $quantityEval = $this->quantityLimits->evaluate($environment, null, 0);

        $gates = $environment === SerproEnvironment::Production
            ? $this->externalGates->listSanitized($environment)
            : [];

        $gatesBlocking = $environment === SerproEnvironment::Production
            && $this->externalGates->anyBlockingProduction($environment);

        $kill = $this->killSwitch->status();
        $runtime = $this->runtimeControlsSanitized();

        $readiness = $this->readiness->evaluateGlobal(
            $environment,
            persist: false,
            trigger: 'CONFIGURATION',
        );
        $readinessArr = is_array($readiness) ? $readiness : $readiness->toSanitizedArray();

        $pendingTenants = $this->pendingTenantsSummary($environment);

        $hasActiveCredential = $activeVersion !== null;
        $hasRecentTest = $activeVersion?->latestValidConnectionEvidence() !== null
            || collect($pendingVersions)->contains(fn (array $v) => ! empty($v['has_recent_connection_test']));

        $ready = $hasActiveCredential
            && ! $gatesBlocking
            && ! ($kill['global']['active'] ?? false)
            && ($quantityEval['allowed'] ?? false);

        return [
            'environment' => $environment->value,
            'endpoints' => $this->officialEndpoints(),
            'contract' => $contract?->toSanitizedArray(),
            'active_credential_version' => $activeVersion?->toSanitizedArray(),
            'pending_credential_versions' => $pendingVersions,
            'credential_history' => $history,
            'external_gates' => $gates,
            'external_gates_blocking' => $gatesBlocking,
            'usage_limits' => [
                'config' => $quantity->toSanitizedArray(),
                'tenant_limits' => $this->quantityLimits->listTenantLimits($environment),
                'usage' => $quantityEval,
            ],
            'runtime_controls' => $runtime,
            'kill_switch' => $kill,
            'readiness' => $readinessArr,
            'pending_tenants' => $pendingTenants,
            'summary' => [
                'has_active_credential' => $hasActiveCredential,
                'has_pending_credential' => $pendingVersions !== [],
                'has_recent_connection_test' => $hasRecentTest,
                'gates_blocking' => $gatesBlocking,
                'kill_switch_active' => (bool) ($kill['global']['active'] ?? false),
                'kill_switch_source' => $kill['global']['source'] ?? null,
                'usage_allowed' => (bool) ($quantityEval['allowed'] ?? false),
                'usage_alert_reached' => (bool) ($quantityEval['alert_reached'] ?? false),
                'configuration_ready' => $ready,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function officialEndpoints(): array
    {
        return [
            'oauth_token_url' => (string) config(
                'serpro.oauth.token_url',
                'https://autenticacao.sapi.serpro.gov.br/authenticate',
            ),
            'api_base_url' => (string) config(
                'serpro.api.base_url',
                'https://gateway.apiserpro.serpro.gov.br/integra-contador/v1',
            ),
            'role_type' => (string) config('serpro.oauth.role_type', 'TERCEIROS'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runtimeControlsSanitized(): array
    {
        return SerproRuntimeControl::query()
            ->orderBy('control_key')
            ->limit(50)
            ->get()
            ->map->toSanitizedArray()
            ->all();
    }

    /**
     * Resumo sanitizado de Tenants com onboarding não pronto (sem dump fiscal).
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    private function pendingTenantsSummary(SerproEnvironment $environment): array
    {
        if (! class_exists(TenantSerproOnboardingState::class)) {
            return ['count' => 0, 'items' => []];
        }

        $readyStatuses = [];
        foreach (TenantSerproOnboardingStatus::cases() as $case) {
            if (str_contains($case->value, 'READY') || $case->value === 'AUTHORIZED') {
                $readyStatuses[] = $case->value;
            }
        }

        $q = TenantSerproOnboardingState::query()
            ->with('tenant:id,name,slug')
            ->where('environment', $environment->value)
            ->when($readyStatuses !== [], fn ($query) => $query->whereNotIn('status', $readyStatuses))
            ->orderByDesc('updated_at')
            ->limit(25);

        $items = $q->get()->map(function (TenantSerproOnboardingState $row): array {
            return [
                'tenant_id' => $row->tenant_id,
                'tenant_name' => $row->tenant?->name,
                'tenant_slug' => $row->tenant?->slug,
                'status' => $row->status instanceof TenantSerproOnboardingStatus
                    ? $row->status->value
                    : (string) $row->status,
                'actionable_code' => $row->actionable_code,
                'settings_path' => '/conta/escritorio',
                'updated_at' => $row->updated_at?->toIso8601String(),
            ];
        })->all();

        return [
            'count' => count($items),
            'items' => $items,
        ];
    }
}
