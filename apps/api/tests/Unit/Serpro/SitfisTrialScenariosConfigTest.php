<?php

namespace Tests\Unit\Serpro;

use Tests\TestCase;

class SitfisTrialScenariosConfigTest extends TestCase
{
    public function test_trial_scenarios_include_sitfis_operations(): void
    {
        $scenarios = config('serpro.environments.TRIAL.scenarios');
        $this->assertIsArray($scenarios);
        $this->assertArrayHasKey('sitfis.solicitar_protocolo', $scenarios);
        $this->assertArrayHasKey('sitfis.emitir_relatorio', $scenarios);

        $solicit = $scenarios['sitfis.solicitar_protocolo'];
        $this->assertSame('00000000000000', $solicit['identity']['numero'] ?? null);
        $this->assertSame(2, $solicit['identity']['tipo'] ?? null);
        $this->assertSame('99999999999', $solicit['contributor']['numero'] ?? null);
        $this->assertStringContainsString('cenarios_sitfis', (string) ($solicit['source_url'] ?? ''));

        $emit = $scenarios['sitfis.emitir_relatorio'];
        $this->assertNotEmpty($emit['business_data']['protocoloRelatorio'] ?? null);
        $this->assertStringContainsString('cenarios_sitfis', (string) ($emit['source_url'] ?? ''));
    }
}
