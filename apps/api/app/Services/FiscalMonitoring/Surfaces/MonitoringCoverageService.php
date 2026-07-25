<?php

namespace App\Services\FiscalMonitoring\Surfaces;

use App\Enums\SerproOfficialState;

/**
 * Projeta a cobertura documental das telas de monitoramento.
 *
 * O contrato público não expõe operation_key, idSistema ou idServico. Ele
 * descreve somente aquilo que a UI precisa para não prometer uma saída que a
 * documentação oficial não define.
 */
final class MonitoringCoverageService
{
    public function __construct(
        private readonly MonitoringSurfaceRegistry $surfaces,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function publicCoverage(): array
    {
        $metadata = $this->surfaces->metadata();
        $rows = [];

        foreach ($this->surfaces->all() as $surface) {
            $operations = collect($surface->capabilities())
                ->flatMap(static fn (MonitoringCapabilityContract $capability): array => $capability->actions)
                ->map(static fn (MonitoringActionContract $action): array => $action->toCoverageCompatibilityArray())
                ->values()
                ->all();

            $public = $surface->toPublicArray();
            $public['operations_total'] = count($operations);
            $public['production_operations'] = count(array_filter(
                $operations,
                static fn (array $operation): bool => $operation['official_state'] === SerproOfficialState::Production->value,
            ));
            $public['mutating_operations'] = count(array_filter(
                $operations,
                static fn (array $operation): bool => $operation['is_mutating'] === true,
            ));
            $public['trial_scenarios'] = count(array_filter(
                $operations,
                static fn (array $operation): bool => $operation['trial_scenario_available'] === true,
            ));
            $public['operations'] = $operations;
            $rows[] = $public;
        }

        return [
            'manifest_version' => $metadata->manifestVersion,
            'verified_at' => $metadata->verifiedAt,
            'truth_note' => 'Catálogo e schema documentados não equivalem a validação fiscal produtiva. Trial usa respostas simuladas fixas do SERPRO.',
            'totals' => [
                'surfaces' => count($rows),
                'catalog_operations' => $metadata->catalogOperations,
                'surface_operations' => array_sum(array_column($rows, 'operations_total')),
                'trial_scenarios' => $metadata->trialScenarios,
            ],
            'surfaces' => $rows,
        ];
    }
}
