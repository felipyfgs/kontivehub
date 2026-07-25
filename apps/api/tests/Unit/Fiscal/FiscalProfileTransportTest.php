<?php

namespace Tests\Unit\Fiscal;

use App\DTO\Serpro\IntegraRequest;
use App\Enums\FiscalSourceProvenance;
use App\Enums\SerproCapabilityDriver;
use App\Services\Integra\FixtureIntegraContadorClient;
use App\Services\Serpro\CapabilityDriverResolver;
use Tests\TestCase;

class FiscalProfileTransportTest extends TestCase
{
    public function test_dev_selects_local_fixture_and_returns_without_network(): void
    {
        config(['fiscal.profile' => 'dev']);

        $this->assertSame(
            SerproCapabilityDriver::Fixture,
            app(CapabilityDriverResolver::class)->forCapability('simples_mei'),
        );

        $response = app(FixtureIntegraContadorClient::class)->execute(new IntegraRequest(
            officeId: 1,
            clientId: 1,
            environment: 'DEV',
            contractorCnpj: '11222333000181',
            authorIdentity: '11222333000181',
            contributorCnpj: '11222333000181',
            operationKey: 'pgdasd.consdeclaracao',
        ));

        $this->assertTrue($response->success);
        $this->assertSame(FiscalSourceProvenance::Fixture->value, $response->sourceProvenance);
        $this->assertFalse($response->simulated);
    }

    public function test_trial_and_production_select_real_transport(): void
    {
        config(['fiscal.profile' => 'trial']);
        $this->assertSame(SerproCapabilityDriver::Real, app(CapabilityDriverResolver::class)->forCapability('mailbox'));

        config(['fiscal.profile' => 'production']);
        $this->assertSame(SerproCapabilityDriver::Real, app(CapabilityDriverResolver::class)->forCapability('mailbox'));
    }
}
