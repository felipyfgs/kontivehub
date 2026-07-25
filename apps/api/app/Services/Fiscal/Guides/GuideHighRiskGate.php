<?php

namespace App\Services\Fiscal\Guides;

use App\Enums\OfficeRole;
use App\Enums\TaxGuideRiskLevel;
use App\Models\Office;
use App\Models\User;
use App\Services\Fiscal\Demo\FiscalDataOriginResolver;
use App\Services\Fiscal\Guides\Exceptions\GuideException;
use App\Services\Fiscal\Mutations\RecentTwoFactorGate;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;

/**
 * Confirmação reforçada + 2FA recente para emissões de alto risco.
 * Deve ser avaliada ANTES de reservar consumo ou chamar a fonte.
 * Reutiliza RecentTwoFactorGate (mesmo challenge que mutações fiscais).
 */
final class GuideHighRiskGate
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly RecentTwoFactorGate $recent2fa,
        private readonly FiscalDataOriginResolver $dataOrigin,
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

        $officeId = $this->currentOffice->id();
        $office = $officeId !== null ? Office::query()->find($officeId) : null;
        if ($mutating && $this->dataOrigin->isDemoOfficeContext($office)) {
            $reasons[] = 'Modo demonstração/somente leitura: emissão externa bloqueada.';
            $codes[] = 'demo_mode';
        }

        if ($mutating && ! FeatureFlags::isMutatingEnabled('guias', $this->currentOffice->id())) {
            $reasons[] = 'Feature flag mutante de guias desabilitada.';
            $codes[] = 'mutating_disabled';
        }

        if ($mutating && ! FeatureFlags::isModuleEnabled('guias', $this->currentOffice->id())) {
            $reasons[] = 'Módulo de guias desabilitado.';
            $codes[] = 'module_disabled';
        }

        $role = $this->currentOffice->role();
        $required = strtoupper((string) config('tax_guides.high_risk.required_role', 'ADMIN'));

        if ($risk->requiresReinforcedConfirmation()) {
            if ($role?->value !== $required && $role !== OfficeRole::Admin) {
                $reasons[] = 'Papel insuficiente para emissão de alto risco.';
                $codes[] = 'role_required';
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
            if ($role === null || $role === OfficeRole::Viewer) {
                $reasons[] = 'Visualizadores não emitem guias.';
                $codes[] = 'role_required';
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

        // Flags de mutação/módulo têm precedência sobre o desafio 2FA
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
        return $this->recent2fa->isRecentlyConfirmed($user);
    }

    public function markConfirmed(User $user): void
    {
        $this->recent2fa->markConfirmed($user);
    }

    public function clear(): void
    {
        $this->recent2fa->clear();
    }

    /**
     * Valida TOTP e marca desafio recente (via RecentTwoFactorGate).
     *
     * @throws GuideException
     */
    public function verifyTotpAndMark(User $user, string $code): void
    {
        try {
            $this->recent2fa->confirmWithCode($user, $code);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $codeKey = str_contains(mb_strtolower($msg), 'inválid')
                ? 'password_invalid'
                : 'password_confirmation_required';
            throw GuideException::forbidden($msg, $codeKey);
        }
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
