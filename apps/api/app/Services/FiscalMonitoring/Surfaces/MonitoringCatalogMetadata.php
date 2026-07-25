<?php

namespace App\Services\FiscalMonitoring\Surfaces;

/** Metadados do catálogo já incorporado ao registro canônico do monitor. */
final readonly class MonitoringCatalogMetadata
{
    public function __construct(
        public string $manifestVersion,
        public string $verifiedAt,
        public int $catalogOperations,
        public int $trialScenarios,
    ) {}
}
