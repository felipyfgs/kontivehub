<?php

namespace Tests\Unit\Work;

use App\Services\Work\ProcessTemplateCatalog;
use App\Services\Work\WorkMonitoringContextRegistry;
use Tests\TestCase;

class ProcessTemplateCatalogTest extends TestCase
{
    public function test_catalog_runtime_artifacts_are_readable_by_php_fpm_user(): void
    {
        $runtimeArtifacts = [
            'config/work_process_catalog.php',
            'app/Http/Controllers/Api/V1/Work/ProcessTemplateCatalogController.php',
            'app/Services/Work/ProcessAudienceResolver.php',
            'app/Services/Work/ProcessTemplateCatalog.php',
            'app/Services/Work/WorkMonitoringContextRegistry.php',
            'database/migrations/2026_07_22_040000_add_orchestration_metadata_to_work_tables.php',
        ];

        foreach ($runtimeArtifacts as $relativePath) {
            $permissions = fileperms(base_path($relativePath));

            $this->assertIsInt($permissions, "Artefato ausente: {$relativePath}");
            $this->assertSame(
                0o004,
                $permissions & 0o004,
                "O PHP-FPM não proprietário precisa conseguir ler {$relativePath}",
            );
        }
    }

    public function test_catalog_exposes_five_versioned_and_ordered_models(): void
    {
        $catalog = app(ProcessTemplateCatalog::class)->all();

        $this->assertSame([
            'PGDAS_MENSAL',
            'FOLHA_MENSAL',
            'FECHAMENTO_CONTABIL',
            'PARCELAMENTO_ENVIO',
            'MEI_MENSAL',
        ], array_keys($catalog));

        foreach ($catalog as $key => $definition) {
            $this->assertGreaterThanOrEqual(1, $definition['version'], $key);
            $this->assertNotEmpty($definition['tasks'], $key);
            $this->assertSame(
                range(1, count($definition['tasks'])),
                array_column($definition['tasks'], 'sort_order'),
                $key,
            );
        }
    }

    public function test_catalog_monitoring_keys_are_allowlisted_and_do_not_expose_external_coordinates(): void
    {
        $catalog = app(ProcessTemplateCatalog::class)->all();
        $registry = app(WorkMonitoringContextRegistry::class);

        foreach ($catalog as $definition) {
            $this->assertTrue($registry->allows($definition['monitoring_module_key']));
        }

        $serialized = json_encode($catalog, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('idSistema', $serialized);
        $this->assertStringNotContainsString('idServico', $serialized);
        $this->assertStringNotContainsString('https://', $serialized);

        $context = $registry->forClient('PGDASD', 42);
        $this->assertSame('/monitoring/clients/42/pgdasd', $context['href']);
        $this->assertNull($registry->forClient('ARBITRARY_URL', 42));
    }
}
