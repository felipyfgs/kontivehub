<?php

namespace App\DTO\Fiscal\Module;

use App\Enums\FiscalDataOrigin;
use App\Enums\FiscalModuleKey;

/**
 * Overview tipado da carteira por módulo (KPIs + metadados sanitizados).
 */
final readonly class ModuleOverviewDto
{
    /**
     * @param  list<ModuleAgendaItemDto>  $agenda
     * @param  list<ModuleCategorySummaryDto>  $categories
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>|null  $surface  resumo público da superfície (sem coordenadas SERPRO)
     */
    public function __construct(
        public FiscalModuleKey $moduleKey,
        public FiscalDataOrigin $dataOrigin,
        public ?string $coverage,
        public ?string $sourceLabel,
        public ?string $asOf,
        public int $totalClients,
        public ModuleCountersDto $counters,
        public array $agenda = [],
        public array $categories = [],
        public array $metrics = [],
        public ?array $surface = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'module_key' => $this->moduleKey->value,
            'module_label' => $this->moduleKey->label(),
            'data_origin' => $this->dataOrigin->value,
            'data_origin_label' => $this->dataOrigin->label(),
            'is_synthetic' => $this->dataOrigin->isSynthetic(),
            'coverage' => $this->coverage,
            'source_label' => $this->sourceLabel,
            'as_of' => $this->asOf,
            'total_clients' => $this->totalClients,
            'counters' => $this->counters->toArray(),
            'agenda' => array_map(static fn (ModuleAgendaItemDto $a) => $a->toArray(), $this->agenda),
            'categories' => array_map(static fn (ModuleCategorySummaryDto $c) => $c->toArray(), $this->categories),
            'metrics' => $this->metrics,
            'surface' => $this->surface,
        ];
    }
}
