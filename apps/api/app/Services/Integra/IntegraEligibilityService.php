<?php

namespace App\Services\Integra;

use App\Contracts\IntegraEligibilityEvaluating;
use App\DTO\Serpro\EligibilityResult;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproConsumptionClass;
use App\Enums\SerproEligibilityCode;
use App\Enums\SerproEnvironment;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\SerproServiceCatalogEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\TenantSubscriptionGate;
use App\Services\Serpro\OfficialClarificationGate;
use App\Services\Serpro\SerproCircuitBreaker;
use App\Services\Serpro\SerproContractService;
use App\Services\Serpro\SerproKillSwitchService;
use App\Services\Serpro\SerproProductionOnboardingGuard;
use App\Services\Serpro\Usage\UsageBudgetGate;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Cache;

/**
 * Matriz de elegibilidade pré-chamada Integra Contador (fail-closed).
 */
final class IntegraEligibilityService implements IntegraEligibilityEvaluating
{
    /** idServico oficial faturável de lookup de procurações. */
    public const BILLABLE_PROXY_LOOKUP_SERVICE = 'OBTERPROCURACAO41';

    public function __construct(
        private readonly TenantSubscriptionGate $subscriptionGate,
        private readonly SerproContractService $contracts,
        private readonly SerproKillSwitchService $killSwitch,
        private readonly SerproCircuitBreaker $breaker,
        private readonly TaxProxyPowerService $proxyPowers,
        private readonly TenantSerproAuthorizationService $authorizations,
        private readonly UsageBudgetGate $budget,
        private readonly RepresentationChainService $representationChain,
        private readonly ProxyPowerMatrixService $powerMatrix,
        private readonly SerproProductionOnboardingGuard $onboardingGuard,
        private readonly OfficialClarificationGate $clarificationGate,
    ) {}

    public function evaluate(
        Tenant $tenant,
        Client $client,
        string $solutionCode,
        string $serviceCode,
        string $operationCode,
        SerproEnvironment $environment,
        ?User $user = null,
        ?string $module = null,
        bool $requireD1 = false,
        bool $freeSmokeMode = false,
    ): EligibilityResult {
        $codes = [];
        $context = [
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'solution' => $solutionCode,
            'service' => $serviceCode,
            'operation' => $operationCode,
            'environment' => $environment->value,
        ];

        // 0. Feature flags
        if ($module !== null && ! FeatureFlags::isModuleEnabled($module, $tenant->id)) {
            $codes[] = SerproEligibilityCode::FeatureDisabled;
        }
        if (FeatureFlags::isKillSwitchActive()) {
            $codes[] = SerproEligibilityCode::KillSwitch;
        }

        // 0b. Tenant demo nunca atinge o endpoint produtivo.
        if ($this->onboardingGuard->isDemoTenant($tenant)
            && $environment === SerproEnvironment::Production
        ) {
            $codes[] = SerproEligibilityCode::DemoTenantBlocked;
        }

        // 1. Kill switch SERPRO / solução
        if ($this->killSwitch->isSolutionBlocked($solutionCode)) {
            $codes[] = SerproEligibilityCode::KillSwitch;
        }

        // 2. Circuit breaker
        if (! $this->breaker->isCallAllowed($solutionCode)) {
            $codes[] = SerproEligibilityCode::CircuitOpen;
        }

        // 3. Assinatura do tenant
        if (! $this->subscriptionGate->allowsExternalCalls($tenant)) {
            $codes[] = SerproEligibilityCode::SubscriptionBlocked;
        }

        // 4. Contrato global
        $contract = $this->contracts->activeFor($environment);
        if ($contract === null || ! $contract->isUsable()) {
            $codes[] = SerproEligibilityCode::ContractUnavailable;
        } elseif ($contract->health_status === 'BLOCKED') {
            $codes[] = SerproEligibilityCode::ContractUnhealthy;
        }

        // 5. Autorização do escritório / Termo / token
        $auth = $this->authorizations->getOrCreate($tenant, $environment);
        if (in_array($auth->status, [
            SerproAuthorizationStatus::Draft,
            SerproAuthorizationStatus::PendingTerm,
            SerproAuthorizationStatus::Revoked,
        ], true)) {
            $codes[] = SerproEligibilityCode::AuthorizationMissing;
        }
        if ($auth->status === SerproAuthorizationStatus::ActionRequired) {
            $codes[] = SerproEligibilityCode::AuthorizationActionRequired;
        }
        if ($auth->status === SerproAuthorizationStatus::Expired
            || ($auth->termo_valid_to !== null && $auth->termo_valid_to->isPast())) {
            $codes[] = SerproEligibilityCode::AuthorizationExpired;
        }
        if (
            $auth->procurador_token_expires_at === null
            || $auth->procurador_token_expires_at->isPast()
        ) {
            if ($auth->status !== SerproAuthorizationStatus::TokenActive) {
                $codes[] = SerproEligibilityCode::TokenMissing;
            } else {
                $codes[] = SerproEligibilityCode::TokenExpired;
            }
        }

        // 5b. Cadeia contratante → autor → contribuinte
        $chain = $this->representationChain->resolve($tenant, $client, $environment, $auth);
        $context['representation_chain'] = $chain->toSanitizedArray();
        if (! $chain->isComplete()) {
            $codes[] = SerproEligibilityCode::RepresentationChainIncomplete;
        }

        // 6. Contribuinte mesmo tenant
        if ($client->tenant_id !== $tenant->id) {
            $codes[] = SerproEligibilityCode::ContributorCrossTenant;
        }

        // 6b. Free smoke: bloquear lookup faturável de procurações
        if ($freeSmokeMode && $this->isBillableProxyLookup($solutionCode, $serviceCode, $operationCode)) {
            $codes[] = SerproEligibilityCode::FreeSmokeBillableBlocked;
        }

        // 6c. Matriz de poderes versionada
        $matrixEval = $this->powerMatrix->evaluateUsability(
            $this->observedSourceHashForPowers()
        );
        $context['power_matrix'] = [
            'review_status' => $matrixEval['review_status'],
            'matrix_version' => $matrixEval['matrix_version'],
            'usable' => $matrixEval['usable'],
        ];
        if (
            $environment === SerproEnvironment::Production
            && ! $matrixEval['usable']
        ) {
            $codes[] = SerproEligibilityCode::PowerMatrixReviewRequired;
        }

        // 7. Catálogo / cobertura / mutabilidade
        $catalog = SerproServiceCatalogEntry::query()
            ->where('environment', $environment->value)
            ->where(function ($q) use ($solutionCode, $serviceCode, $operationCode): void {
                $q->where(function ($q2) use ($solutionCode, $serviceCode, $operationCode): void {
                    $q2->where('solution_code', $solutionCode)
                        ->where('service_code', $serviceCode)
                        ->where('operation_code', $operationCode);
                })->orWhere(function ($q2) use ($solutionCode, $serviceCode, $operationCode): void {
                    // Coordenadas oficiais idSistema/idServico
                    $q2->where('id_sistema', $solutionCode)
                        ->where('id_servico', $serviceCode)
                        ->orWhere(function ($q3) use ($solutionCode, $operationCode): void {
                            $q3->where('id_sistema', $solutionCode)
                                ->where('id_servico', $operationCode);
                        });
                });
            })
            ->where('is_enabled', true)
            ->orderByDesc('catalog_version')
            ->first();

        // O manifesto oficial é projetado como catálogo canônico de produção.
        // Trial reutiliza essas coordenadas somente quando não
        // houver uma entrada específica do ambiente de execução.
        if ($catalog === null && $environment !== SerproEnvironment::Production) {
            $catalog = SerproServiceCatalogEntry::query()
                ->where('environment', SerproEnvironment::Production->value)
                ->where(function ($q) use ($solutionCode, $serviceCode, $operationCode): void {
                    $q->where(function ($q2) use ($solutionCode, $serviceCode, $operationCode): void {
                        $q2->where('solution_code', $solutionCode)
                            ->where('service_code', $serviceCode)
                            ->where('operation_code', $operationCode);
                    })->orWhere(function ($q2) use ($solutionCode, $serviceCode, $operationCode): void {
                        $q2->where('id_sistema', $solutionCode)
                            ->where('id_servico', $serviceCode)
                            ->orWhere(function ($q3) use ($solutionCode, $operationCode): void {
                                $q3->where('id_sistema', $solutionCode)
                                    ->where('id_servico', $operationCode);
                            });
                    });
                })
                ->where('is_enabled', true)
                ->orderByDesc('catalog_version')
                ->first();
        }

        if ($catalog === null) {
            $codes[] = SerproEligibilityCode::ServiceNotCataloged;
        } else {
            if ($catalog->coverage === 'UNSUPPORTED') {
                $codes[] = SerproEligibilityCode::CoverageUnsupported;
            }
            if ($catalog->is_mutating) {
                if ($module === null || ! FeatureFlags::isMutatingEnabled($module, $tenant->id)) {
                    $codes[] = SerproEligibilityCode::MutatingDisabled;
                }
            }

            // 8. Procuração / poder — matriz + catálogo
            $requiredPowers = $this->resolveRequiredPowers(
                $catalog,
                $solutionCode,
                $serviceCode,
                $operationCode,
            );

            // Lista da matriz/catálogo é ANY-of (alternativas e-CAC), não AND.
            if ($requiredPowers !== []) {
                $anyUsable = false;
                $diagCodes = [];
                foreach ($requiredPowers as $requiredPower) {
                    $power = $this->proxyPowers->findUsablePower(
                        tenantId: $tenant->id,
                        clientId: $client->id,
                        powerCode: $requiredPower,
                        authorIdentity: (string) $auth->author_identity,
                        environment: $environment,
                        requireD1: $requireD1,
                        requireFresh: true,
                        requireAccept: true,
                    );
                    if ($power !== null) {
                        $anyUsable = true;
                        break;
                    }
                    foreach ($this->proxyPowers->diagnoseUnusable(
                        $tenant->id,
                        $client->id,
                        $requiredPower,
                        (string) $auth->author_identity,
                        $environment,
                        $requireD1,
                    ) as $reason) {
                        $diagCodes[] = $reason;
                    }
                }
                if (! $anyUsable) {
                    foreach (array_values(array_unique($diagCodes)) as $reason) {
                        $codes[] = SerproEligibilityCode::tryFrom($reason)
                            ?? SerproEligibilityCode::ProxyPowerMissing;
                    }
                }
            }
        }

        // 8b. Gate CNPJ alfanumérico em Eventos (quando D-1 / monitorar)
        if ($requireD1 && $chain->contributorCnpj !== '') {
            $cnpjGate = $this->clarificationGate->evaluateCnpjField(
                $chain->contributorCnpj,
                OfficialClarificationGate::CONTEXT_EVENTOS_PAYLOAD,
                $environment,
            );
            if (! $cnpjGate['allowed'] && $cnpjGate['code'] !== null) {
                $codes[] = $cnpjGate['code'];
            }
        }

        // 9. Papel do usuário (se presente)
        if ($user !== null) {
            $membership = $user->memberships()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->first();
            if ($membership === null) {
                $codes[] = SerproEligibilityCode::RoleForbidden;
            } elseif ($membership->role === TenantRole::TenantUser) {
                $codes[] = SerproEligibilityCode::RoleForbidden;
            }
        }

        // 10. Orçamento
        $consumptionClass = $this->resolveConsumptionClass($catalog);
        $budgetEval = $this->budget->evaluate(
            tenantId: (int) $tenant->id,
            class: $consumptionClass,
            quantity: 1,
            isEssential: false,
        );
        if (! (bool) $budgetEval['allowed']) {
            $codes[] = SerproEligibilityCode::BudgetExceeded;
        }
        $context['budget_used'] = $budgetEval['used_quantity'];
        $context['budget_reserved_open'] = $budgetEval['reserved_open_quantity'];
        $context['budget_quota'] = $budgetEval['franchise_quota'];
        $context['budget_would_block'] = $budgetEval['would_block'];
        $context['budget_block_reason'] = $budgetEval['block_reason'];

        // 11. Rate limit simples
        $perTenant = (int) config('serpro.rate_limit.per_tenant_per_minute', 0);
        $rateKey = 'serpro.rate.tenant.'.$tenant->id.'.'.now()->format('YmdHi');
        $hits = (int) Cache::get($rateKey, 0);
        if ($perTenant > 0 && $hits >= $perTenant) {
            $codes[] = SerproEligibilityCode::RateLimited;
        }

        // Dedup codes
        $unique = [];
        foreach ($codes as $code) {
            $unique[$code->value] = $code;
        }
        $codes = array_values($unique);

        $blocking = array_values(array_filter(
            $codes,
            fn (SerproEligibilityCode $c) => $c->isBlocking(),
        ));

        if ($blocking !== []) {
            return EligibilityResult::blockedMany($blocking, $context);
        }

        return EligibilityResult::ok($context);
    }

    public function touchRateLimit(int $tenantId): void
    {
        if ((int) config('serpro.rate_limit.per_tenant_per_minute', 0) <= 0) {
            return;
        }

        $rateKey = 'serpro.rate.tenant.'.$tenantId.'.'.now()->format('YmdHi');
        if (! Cache::has($rateKey)) {
            Cache::put($rateKey, 1, 120);
        } else {
            Cache::increment($rateKey);
        }
    }

    private function resolveConsumptionClass(?SerproServiceCatalogEntry $catalog): SerproConsumptionClass
    {
        if ($catalog === null) {
            return SerproConsumptionClass::Consulta;
        }

        $raw = $catalog->billable_class;
        $value = $raw instanceof \BackedEnum ? $raw->value : (string) ($raw ?? 'CONSULTA');

        return SerproConsumptionClass::tryFrom(strtoupper($value))
            ?? SerproConsumptionClass::Consulta;
    }

    /**
     * @return list<string>
     */
    private function resolveRequiredPowers(
        SerproServiceCatalogEntry $catalog,
        string $solutionCode,
        string $serviceCode,
        string $operationCode,
    ): array {
        $powers = [];

        $metaPowers = $catalog->metadata['required_proxy_powers'] ?? null;
        if (is_array($metaPowers)) {
            foreach ($metaPowers as $p) {
                $p = strtoupper(trim((string) $p));
                if ($p !== '') {
                    $powers[] = $p;
                }
            }
        }

        if ($catalog->required_proxy_power !== null && $catalog->required_proxy_power !== '') {
            // Catálogo pode listar alternativas no mesmo campo: "00076 00188".
            foreach (preg_split('/\s+/', trim((string) $catalog->required_proxy_power)) ?: [] as $part) {
                $part = strtoupper(trim($part));
                if ($part !== '') {
                    $powers[] = $part;
                }
            }
        }

        // Matriz oficial idSistema+idServico
        $idSistema = (string) ($catalog->id_sistema ?: $catalog->solution_code ?: $solutionCode);
        $idServico = (string) ($catalog->id_servico ?: $catalog->operation_code ?: $operationCode);
        foreach ($this->powerMatrix->requiredPowers($idSistema, $idServico) as $p) {
            $powers[] = $p;
        }

        // Também tenta service_code como idServico
        if ($serviceCode !== $idServico) {
            foreach ($this->powerMatrix->requiredPowers($idSistema, $serviceCode) as $p) {
                $powers[] = $p;
            }
        }

        return array_values(array_unique($powers));
    }

    private function isBillableProxyLookup(string $solutionCode, string $serviceCode, string $operationCode): bool
    {
        $needles = [
            strtoupper($serviceCode),
            strtoupper($operationCode),
            strtoupper($solutionCode),
        ];

        return in_array(self::BILLABLE_PROXY_LOOKUP_SERVICE, $needles, true)
            || in_array('PROCURACOES.OBTER', $needles, true)
            || str_contains(strtoupper($operationCode), 'OBTERPROCURACAO');
    }

    private function observedSourceHashForPowers(): ?string
    {
        $configured = config('serpro.proxy_powers.observed_source_sha256');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }
}
