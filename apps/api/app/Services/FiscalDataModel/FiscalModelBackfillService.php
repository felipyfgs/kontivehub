<?php

namespace App\Services\FiscalDataModel;

use App\Support\FiscalDataModel\BackfillResult;
use App\Support\FiscalDataModel\FiscalModelAggregates;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Biblioteca de backfill com checkpoint, idempotência e mapa origem-destino.
 * Implementações por agregado evoluem nas fases 3–7; a harness já é reiniciável.
 */
class FiscalModelBackfillService
{
    public function run(string $aggregate, bool $dryRun = true, ?int $officeId = null): BackfillResult
    {
        if (! FiscalModelAggregates::isKnown($aggregate)) {
            throw new InvalidArgumentException("Agregado desconhecido: {$aggregate}");
        }

        if (! Schema::hasTable('fiscal_model_migration_maps')) {
            throw new RuntimeException(
                'Tabela fiscal_model_migration_maps ausente. Rode as migrations da harness fiscal-data-model.',
            );
        }

        return match ($aggregate) {
            FiscalModelAggregates::TENANCY_CADASTRO => app(CadastroCollapseService::class)->run($dryRun, $officeId),
            FiscalModelAggregates::DOCUMENTOS_CURSORES => app(DocumentProvenanceBackfillService::class)->run($dryRun, $officeId),
            FiscalModelAggregates::OUTBOUND => app(OutboundRecoveryBackfillService::class)->run($dryRun, $officeId),
            FiscalModelAggregates::SERPRO => app(SerproCatalogBackfillService::class)->run($dryRun, $officeId),
            FiscalModelAggregates::MONITORAMENTO_GUIAS => app(MonitoringGuideBackfillService::class)->run($dryRun, $officeId),
            default => $this->noopAggregate($aggregate, $dryRun),
        };
    }

    private function noopAggregate(string $aggregate, bool $dryRun): BackfillResult
    {
        return new BackfillResult(
            aggregate: $aggregate,
            dryRun: $dryRun,
            processed: 0,
            mapped: 0,
            skipped: 0,
            rejected: 0,
            ambiguous: 0,
            rejections: [],
            ambiguities: [],
            checkpoint: null,
        );
    }
}
