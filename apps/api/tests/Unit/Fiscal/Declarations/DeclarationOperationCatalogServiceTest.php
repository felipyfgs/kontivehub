<?php

namespace Tests\Unit\Fiscal\Declarations;

use App\Services\Fiscal\Declarations\DeclarationOperationCatalogService;
use App\Services\Fiscal\Declarations\DeclarationOperationRegistry;
use App\Services\Fiscal\ManualConsult\ManualConsultActionCatalog;
use Tests\TestCase;

final class DeclarationOperationCatalogServiceTest extends TestCase
{
    public function test_it_projects_the_exact_official_declaration_inventory_without_coordinates(): void
    {
        $catalog = app(DeclarationOperationCatalogService::class)->publicCatalog();

        $this->assertSame(33, $catalog['counts']['total']);
        $this->assertSame(23, $catalog['counts']['production']);
        $this->assertSame(10, $catalog['counts']['prospection']);
        $this->assertSame(17, $catalog['counts']['read']);
        $this->assertSame(16, $catalog['counts']['mutation']);
        $this->assertSame(13, $catalog['counts']['production_read']);
        $this->assertSame(10, $catalog['counts']['production_mutation']);
        $this->assertSame(23, $catalog['counts']['executable']);
        $this->assertCount(33, $catalog['operations']);
        $this->assertCount(33, array_unique(array_column($catalog['operations'], 'action_id')));

        $encoded = json_encode($catalog, JSON_THROW_ON_ERROR);
        foreach (['operation_key', 'id_sistema', 'id_servico', 'versao_sistema', 'request_schema', 'response_schema'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_it_distinguishes_available_controlled_and_prospection_operations(): void
    {
        $operations = collect(app(DeclarationOperationCatalogService::class)->publicCatalog()['operations'])
            ->keyBy('action_id');

        $read = $operations->get('decl_pgdas_consultar_declaracoes');
        $this->assertSame('AVAILABLE', $read['availability']);
        $this->assertSame('READ', $read['flow']);
        $this->assertTrue($read['executable']);

        $mutation = $operations->get('decl_dctfweb_transmitir');
        $this->assertSame('CONTROLLED', $mutation['availability']);
        $this->assertSame('MUTATION', $mutation['flow']);
        $this->assertTrue($mutation['requires_preflight']);
        $this->assertTrue($mutation['executable']);

        $prospection = $operations->get('decl_dasn_consultar');
        $this->assertSame('PROSPECTION', $prospection['availability']);
        $this->assertSame('PROSPECTION', $prospection['official_state']);
        $this->assertFalse($prospection['executable']);
    }

    public function test_registry_resolves_only_allowlisted_public_action_ids(): void
    {
        $registry = app(DeclarationOperationRegistry::class);

        $this->assertSame(
            'pgdasd.consdeclaracao',
            $registry->operationKeyFor('decl_pgdas_consultar_declaracoes'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $registry->operationKeyFor('dctfweb.transdeclaracao');
    }

    public function test_all_thirteen_production_reads_have_a_tenant_safe_manual_handler(): void
    {
        $registry = app(DeclarationOperationRegistry::class);
        $manual = app(ManualConsultActionCatalog::class);
        $reads = collect(app(DeclarationOperationCatalogService::class)->publicCatalog()['operations'])
            ->where('official_state', 'PRODUCTION')
            ->where('flow', 'READ')
            ->values();

        $this->assertCount(13, $reads);
        foreach ($reads as $operation) {
            $definition = $manual->findByOperationKey(
                $registry->operationKeyFor($operation['action_id']),
            );
            $this->assertNotNull($definition, $operation['action_id'].' sem definição manual.');
            $this->assertTrue($definition->hasHandler, $operation['action_id'].' sem handler.');
            $this->assertNotSame('none', $definition->handler);
        }
    }
}
