<?php

namespace App\Console\Commands;

use App\Services\Serpro\Catalog\OfficialServiceCatalogImporter;
use App\Services\Serpro\Catalog\OfficialServiceCatalogManifest;
use Illuminate\Console\Command;

/**
 * Valida (e opcionalmente importa) o manifesto oficial Integra Contador.
 */
final class SerproCatalogValidateCommand extends Command
{
    protected $signature = 'serpro:catalog-validate
                            {--import : Importa projeção no banco após validar}
                            {--path= : Caminho absoluto do manifesto JSON}';

    protected $description = 'Valida integridade do manifesto oficial SERPRO (119 entradas)';

    public function handle(
        OfficialServiceCatalogManifest $manifest,
        OfficialServiceCatalogImporter $importer,
    ): int {
        $path = $this->option('path') ?: null;
        $result = $manifest->validate(null, $path);

        $routes = $result['route_counts'] ?? [];
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['válido', $result['valid'] ? 'sim' : 'não'],
                ['versão', $result['manifest_version'] ?? '—'],
                ['total', (string) $result['total']],
                ['PRODUCTION', (string) ($result['counts']['PRODUCTION'] ?? 0)],
                ['PROSPECTION', (string) ($result['counts']['PROSPECTION'] ?? 0)],
                ['UNDER_CONSTRUCTION', (string) ($result['counts']['UNDER_CONSTRUCTION'] ?? 0)],
                ['CANCELED', (string) ($result['counts']['CANCELED'] ?? 0)],
                ['Apoiar', (string) ($routes['Apoiar'] ?? 0)],
                ['Consultar', (string) ($routes['Consultar'] ?? 0)],
                ['Declarar', (string) ($routes['Declarar'] ?? 0)],
                ['Emitir', (string) ($routes['Emitir'] ?? 0)],
                ['Monitorar', (string) ($routes['Monitorar'] ?? 0)],
                ['keys únicas', $result['unique_operation_keys'] ? 'sim' : 'não'],
                ['coords únicas', $result['unique_coordinates'] ? 'sim' : 'não'],
                ['sha256', $result['sha256'] ?? '—'],
            ],
        );

        if ($result['errors'] !== []) {
            foreach ($result['errors'] as $error) {
                $this->error($error);
            }
        }

        if (! $result['valid']) {
            return self::FAILURE;
        }

        if ($this->option('import')) {
            $import = $importer->import($path);
            $this->info(sprintf(
                'Import: +%d novos, %d atualizados, %d inalterados',
                $import['imported'],
                $import['updated'],
                $import['skipped'],
            ));
            if (! $import['valid']) {
                return self::FAILURE;
            }
        }

        $this->info('Manifesto SERPRO íntegro.');

        return self::SUCCESS;
    }
}
