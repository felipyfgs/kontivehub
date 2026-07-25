<?php

namespace Tests\Unit\Fiscal;

use App\Enums\FiscalOperationClass;
use App\Services\Fiscal\ManualConsult\ManualConsultActionCatalog;
use App\Services\FiscalMonitoring\Surfaces\MonitoringSurfaceRegistry;
use App\Services\Serpro\Catalog\OfficialServiceCatalogManifest;
use Tests\TestCase;

class MonitoringSurfaceRegistryTest extends TestCase
{
    public function test_registry_is_hierarchical_canonical_and_integral_against_manifest(): void
    {
        $registry = app(MonitoringSurfaceRegistry::class);
        $manifest = app(OfficialServiceCatalogManifest::class)->load();
        $metadata = $registry->metadata();
        $officialKeys = array_column($manifest['entries'], 'operation_key');
        $registeredKeys = [];

        $this->assertSame($manifest['manifest_version'], $metadata->manifestVersion);
        $this->assertSame($manifest['verified_at'], $metadata->verifiedAt);
        $this->assertSame(count($manifest['entries']), $metadata->catalogOperations);

        foreach ($registry->all() as $surface) {
            foreach ($surface->capabilities() as $capability) {
                $this->assertNotSame('', $capability->capabilityKey);
                foreach ($capability->operationKeys() as $operationKey) {
                    $this->assertContains($operationKey, $officialKeys);
                    $registeredKeys[] = $operationKey;
                }
                foreach ($capability->actions as $action) {
                    $this->assertNotSame('', $action->label);
                    $this->assertNotSame('', $action->sourceLabel);
                    $this->assertIsArray($action->paramsSchema);
                    $this->assertIsArray($action->outputFields);
                    if ($action->available) {
                        $this->assertSame(FiscalOperationClass::Read, $action->operationClass);
                        $this->assertNotSame('none', $action->handler);
                    }
                }
            }
        }

        $this->assertSame('/monitoring/simples-mei', $registry->get('simples_mei_pgdasd')->routePattern);
        $this->assertSame('/monitoring/simples-mei', $registry->get('simples_mei_pgmei')->routePattern);
        $this->assertSame('/monitoring/dctfweb', $registry->get('dctfweb')->routePattern);
        $this->assertSame('/monitoring/dctfweb', $registry->get('mit')->routePattern);

        foreach ([
            'defis.consdeclaracao',
            'ccmei.dadosccmei',
            'regimeapuracao.consultaranoscalendarios',
            'sicalc.consultaapoioreceitas',
            'pagtoweb.contaconsdocarrpg',
        ] as $formerlyExceptionalKey) {
            $this->assertContains($formerlyExceptionalKey, $registeredKeys);
            $this->assertNotNull(app(ManualConsultActionCatalog::class)->findByOperationKey($formerlyExceptionalKey));
        }

        $publicJson = json_encode(
            array_map(static fn ($surface) => $surface->toPublicArray(), $registry->all()),
            JSON_THROW_ON_ERROR,
        );
        $this->assertStringNotContainsString('operation_key', $publicJson);
        $this->assertStringNotContainsString('idSistema', $publicJson);
        $this->assertStringNotContainsString('idServico', $publicJson);
    }
}
