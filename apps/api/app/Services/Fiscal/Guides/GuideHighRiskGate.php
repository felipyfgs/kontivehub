<?php

namespace App\Services\Fiscal\Guides;

use App\Enums\TaxGuideRiskLevel;
use App\Enums\TenantPermission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Demo\FiscalDataOriginResolver;
use App\Services\Fiscal\Guides\Exceptions\GuideException;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;

/**
 * Confirmação reforçada + senha recente para emissões de alto risco.
 * Deve ser avaliada ANTES de reservar consumo ou chamar a fonte.
 */
final class GuideHighRiskGate
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly RecentPasswordConfirmationGate $recentPassword,
        private readonly FiscalDataOriginResolver $dataOrigin,
        private readonly TenantAuthorization $authorization,
    ) {}

    /**
     * @param  array<string, mixed>|null  $confirmationSummary  resumo exibido ao operador
     * @return array{allowed:bool,reasons:list<string>,codes:list<string>,risk:TaxGuideRiskLevel,requires_challenge:bool}
     */
    public function evaluate(
        TaxGuideRiskLevel $risk,
        ?User $user,
        bool $explicitConfirmation,
        ?array $confirmationSummary = null,
        bool $mutating = true,
    ): array {
        $reasons = [];
        $codes = [];

        $tenantId = $this->currentTenant->id();
        $tenant = $tenantId !== null ? Tenant::query()->find($tenantId) : null;
        if ($mutating && $this->dataOrigin->isDemoTenantContext($tenant)) {
            $reasons[] = 'Modo demonstração/somente leitura: emissão externa bloqueada.';
            $codes[] = 'demo_mode';
        }

        if ($mutating && ! FeatureFlags::isMutatingEnabled('guides', $this->currentTenant->id())) {
            $reasons[] = 'Feature flag mutante de guias desabilitada.';
            $codes[] = 'mutating_disabled';
        }

        if ($mutating && ! FeatureFlags::isModuleEnabled('guides', $this->currentTenant->id())) {
            $reasons[] = 'Módulo de guias desabilitado.';
            $codes[] = 'module_disabled';
        }

        $canExecute = $user !== null
            && $this->authorization->allows($user, TenantPermission::FiscalMutationsExecute);

        if ($risk->requiresReinforcedConfirmation()) {
            if (! $canExecute) {
                $reasons[] = 'Permissão insuficiente para emissão de alto risco.';
                $codes[] = 'permission_required';
            }

            if ($user === null) {
                $reasons[] = 'Usuário autenticado é obrigatório para emissão de alto risco.';
                $codes[] = 'auth_required';
            }

            if (! $this->hasRecentChallenge($user)) {
                $reasons[] = 'Reconfirmação de senha recente ausente ou expirada.';
                $codes[] = 'high_risk_challenge_required';
            }

            if (! $explicitConfirmation) {
                $reasons[] = 'Confirmação explícita do resumo fiscal é obrigatória.';
                $codes[] = 'explicit_confirmation_required';
            }

            if ($confirmationSummary === null || $confirmationSummary === []) {
                $reasons[] = 'Resumo de contribuinte/competência/valor ausente.';
                $codes[] = 'confirmation_summary_required';
            }
        } else {
            if (! $canExecute) {
                $reasons[] = 'Permissão insuficiente para emissão de guias.';
                $codes[] = 'permission_required';
            }
        }

        return [
            'allowed' => $reasons === [],
            'reasons' => $reasons,
            'codes' => $codes,
            'risk' => $risk,
            'requires_challenge' => in_array('high_risk_challenge_required', $codes, true),
        ];
    }

    /**
     * @throws GuideException
     */
    public function assertAllowed(
        TaxGuideRiskLevel $risk,
        ?User $user,
        bool $explicitConfirmation,
        ?array $confirmationSummary = null,
        bool $mutating = true,
    ): void {
        $eval = $this->evaluate($risk, $user, $explicitConfirmation, $confirmationSummary, $mutating);
        if ($eval['allowed']) {
            return;
        }

        // Flags de mutação/módulo têm precedência sobre o desafio de senha.
        if (in_array('mutating_disabled', $eval['codes'], true) || in_array('module_disabled', $eval['codes'], true)) {
            throw GuideException::mutatingDisabled();
        }

        if ($eval['requires_challenge']) {
            throw GuideException::challengeRequired(implode(' ', $eval['reasons']));
        }

        throw GuideException::forbidden(implode(' ', $eval['reasons']), $eval['codes'][0] ?? 'guide_forbidden');
    }

    public function hasRecentChallenge(?User $user = null): bool
    {
        return $this->recentPassword->isRecentlyConfirmed($user);
    }

    public function markConfirmed(User $user): void
    {
        $this->recentPassword->markConfirmed($user);
    }

    public function clear(): void
    {
        $this->recentPassword->clear();
    }

    public function resolveRisk(TaxGuideRiskLevel $catalogRisk, ?int $amountCents): TaxGuideRiskLevel
    {
        if ($catalogRisk === TaxGuideRiskLevel::High) {
            return TaxGuideRiskLevel::High;
        }

        $threshold = (int) config('tax_guides.high_risk.amount_threshold_cents', 0);
        if ($threshold > 0 && $amountCents !== null && $amountCents >= $threshold) {
            return TaxGuideRiskLevel::High;
        }

        // Emissões mutantes de guia são HIGH por default no MVP.
        return TaxGuideRiskLevel::High;
    }
}
