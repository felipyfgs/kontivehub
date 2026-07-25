<?php

namespace App\Services\Serpro\Usage;

/**
 * Política de shadow mode e bloqueio comercial do ledger.
 *
 * Defaults: shadow ON, commercial blocking OFF.
 * Shadow mode vence: com shadow ativo, bloqueio comercial nunca aplica.
 * Produção efetiva (blocking on) é deny-by-default para unknown/preço/orçamento.
 */
final class UsageShadowPolicy
{
    public function isShadowMode(): bool
    {
        return (bool) config('serpro_usage.shadow_mode', true);
    }

    public function isCommercialBlockingEnabled(): bool
    {
        if ($this->isShadowMode()) {
            return false;
        }

        return (bool) config('serpro_usage.commercial_blocking_enabled', false);
    }

    /**
     * Modo produtivo de cobrança: aplica fail-closed e budgets monetários.
     */
    public function isProductiveBillingMode(): bool
    {
        return $this->isCommercialBlockingEnabled();
    }

    public function failOpenOnUnknown(): bool
    {
        if ($this->isProductiveBillingMode()) {
            return false;
        }

        return (bool) config('serpro_usage.fail_open_on_unknown', false);
    }

    public function requiresPositiveMonetaryBudgets(): bool
    {
        if (! $this->isProductiveBillingMode()) {
            return false;
        }

        return (bool) config('serpro_usage.require_positive_monetary_budgets', true);
    }

    public function requiresProductionPriceTable(): bool
    {
        if (! $this->isProductiveBillingMode()) {
            return false;
        }

        return (bool) config('serpro_usage.require_production_price_table', true);
    }

    /**
     * @return array{
     *   shadow_mode: bool,
     *   commercial_blocking_enabled: bool,
     *   effective_blocking: bool,
     *   productive_billing: bool,
     *   fail_open_on_unknown: bool
     * }
     */
    public function snapshot(): array
    {
        $shadow = $this->isShadowMode();
        $configuredBlocking = (bool) config('serpro_usage.commercial_blocking_enabled', false);

        return [
            'shadow_mode' => $shadow,
            'commercial_blocking_enabled' => $configuredBlocking,
            'effective_blocking' => $this->isCommercialBlockingEnabled(),
            'productive_billing' => $this->isProductiveBillingMode(),
            'fail_open_on_unknown' => $this->failOpenOnUnknown(),
        ];
    }
}
