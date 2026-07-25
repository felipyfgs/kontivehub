<?php

namespace App\Services\FiscalMonitoring\Surfaces;

use App\Enums\FiscalOperationClass;
use App\Enums\MonitoringChannel;
use App\Enums\MonitoringDocumentPolicy;
use App\Enums\MonitoringOfficialStateSummary;
use App\Enums\MonitoringResultKind;

/**
 * Contrato backend de uma superfície da page-payload-matrix.
 * operation_keys permanecem internos; toPublicArray() não as expõe.
 */
final readonly class MonitoringSurfaceContract
{
    /**
     * @param  list<string>  $operationKeys
     * @param  list<MonitoringCapabilityContract>  $capabilityContracts
     */
    public function __construct(
        public string $surfaceKey,
        public string $routePattern,
        public string $responsibility,
        public MonitoringChannel $channel,
        public array $operationKeys,
        public MonitoringOfficialStateSummary $officialState,
        public MonitoringResultKind $resultKind,
        public bool $allowsDocument,
        public MonitoringDocumentPolicy $documentPolicy,
        public string $sourceLabel,
        public array $capabilityContracts = [],
    ) {}

    /**
     * Resumo público para UI — sem idSistema/idServico/operation_key.
     *
     * @return array{
     *   surface_key: string,
     *   route: string,
     *   responsibility: string,
     *   result_kind: string,
     *   allows_document: bool,
     *   official_state_label: string,
     *   channel_label: string,
     *   source_label: string
     * }
     */
    public function toPublicArray(): array
    {
        return [
            'surface_key' => $this->surfaceKey,
            'route' => $this->routePattern,
            'responsibility' => $this->responsibility,
            'result_kind' => $this->resultKind->value,
            'allows_document' => $this->allowsDocument,
            'official_state_label' => $this->officialState->label(),
            'channel_label' => $this->channel->label(),
            'source_label' => $this->sourceLabel,
            'capabilities' => array_map(
                static fn (MonitoringCapabilityContract $capability): array => $capability->toPublicArray(),
                $this->capabilities(),
            ),
        ];
    }

    /**
     * Projeção hierárquica canônica. O prefixo da operation_key é o
     * identificador público estável da capability; coordenadas permanecem
     * exclusivamente no manifesto interno.
     *
     * @return list<MonitoringCapabilityContract>
     */
    public function capabilities(): array
    {
        if ($this->capabilityContracts !== []) {
            return $this->capabilityContracts;
        }

        $grouped = [];
        foreach ($this->operationKeys as $operationKey) {
            [$capabilityKey] = explode('.', $operationKey, 2);
            $grouped[$capabilityKey][] = new MonitoringActionContract(
                actionKey: str_replace('.', '_', $operationKey),
                operationKey: $operationKey,
                label: $operationKey,
                operationClass: FiscalOperationClass::Read,
                paramsSchema: [],
                resultKind: $this->resultKind,
                documentPolicy: $this->documentPolicy,
                handler: 'none',
                available: false,
                officialState: $this->officialState->value,
                sourceLabel: $this->sourceLabel,
                moduleKey: 'unknown',
                featureModule: null,
                requiredProxyPowers: [],
                runCodes: null,
                async: $this->resultKind === MonitoringResultKind::AsyncPdf,
                outputFields: [],
                officialRoute: '',
                trialScenarioAvailable: false,
                requestDocumented: false,
                responseDocumented: false,
            );
        }

        return array_values(array_map(
            static fn (array $actions, string $key): MonitoringCapabilityContract => new MonitoringCapabilityContract(
                capabilityKey: $key,
                label: match ($key) {
                    'pgdasd' => 'PGDAS-D',
                    'pgmei' => 'PGMEI',
                    'defis' => 'DEFIS',
                    'ccmei' => 'CCMEI',
                    'regimeapuracao' => 'Regime de Apuração',
                    'dctfweb' => 'DCTFWeb',
                    'mit' => 'MIT',
                    'sicalc' => 'Sicalc',
                    'pagtoweb' => 'PagtoWeb',
                    default => strtoupper(str_replace('_', ' ', $key)),
                },
                actions: $actions,
            ),
            $grouped,
            array_keys($grouped),
        ));
    }
}
