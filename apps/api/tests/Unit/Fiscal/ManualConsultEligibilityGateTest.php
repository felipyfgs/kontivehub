<?php

namespace Tests\Unit\Fiscal;

use App\Enums\ManualConsultEligibility;
use App\Enums\SerproEnvironment;
use App\Models\Office;
use App\Services\Fiscal\ManualConsult\ManualConsultActionDefinition;
use App\Services\Fiscal\ManualConsult\ManualConsultEligibilityGate;
use Tests\TestCase;

class ManualConsultEligibilityGateTest extends TestCase
{
    public function test_trial_with_handler_is_ready(): void
    {
        config(['serpro.kill_switch' => false]);

        $eligibility = app(ManualConsultEligibilityGate::class)->evaluateWithContext(
            office: new Office,
            def: $this->definition(),
            hasToken: false,
            client: null,
            auth: null,
            environment: SerproEnvironment::Trial,
        );

        $this->assertSame(ManualConsultEligibility::Ready, $eligibility);
    }

    public function test_production_without_token_is_token_missing(): void
    {
        config(['serpro.kill_switch' => false]);

        $eligibility = app(ManualConsultEligibilityGate::class)->evaluateWithContext(
            office: new Office,
            def: $this->definition(),
            hasToken: false,
            client: null,
            auth: null,
            environment: SerproEnvironment::Production,
        );

        $this->assertSame(ManualConsultEligibility::TokenMissing, $eligibility);
    }

    private function definition(): ManualConsultActionDefinition
    {
        return new ManualConsultActionDefinition(
            actionId: 'simples-mei:pgmei-divida',
            operationKey: 'pgmei.dividaativa',
            label: 'Dívida ativa PGMEI',
            surfaceKey: 'simples-mei',
            moduleKey: 'pgmei',
            featureModule: null,
            handler: 'PgmeiDividaAtivaHandler',
            hasHandler: true,
        );
    }
}
