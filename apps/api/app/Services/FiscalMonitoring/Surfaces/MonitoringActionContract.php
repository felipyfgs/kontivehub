<?php

namespace App\Services\FiscalMonitoring\Surfaces;

use App\Enums\FiscalOperationClass;
use App\Enums\MonitoringDocumentPolicy;
use App\Enums\MonitoringResultKind;

/**
 * Vínculo tipado entre uma capability e uma operação do manifesto oficial.
 */
final readonly class MonitoringActionContract
{
    /**
     * @param  list<array{name: string, type: string, required: bool, label: string, pattern?: string|null}>  $paramsSchema
     * @param  list<array{name: string, type: string}>  $outputFields
     * @param  list<string>  $requiredProxyPowers
     * @param  array{system: string, service: string, operation: string}|null  $runCodes
     */
    public function __construct(
        public string $actionKey,
        public string $operationKey,
        public string $label,
        public FiscalOperationClass $operationClass,
        public array $paramsSchema,
        public MonitoringResultKind $resultKind,
        public MonitoringDocumentPolicy $documentPolicy,
        public string $handler,
        public bool $available,
        public string $officialState,
        public string $sourceLabel,
        public string $moduleKey,
        public ?string $featureModule,
        public array $requiredProxyPowers,
        public ?array $runCodes,
        public bool $async,
        public array $outputFields,
        public string $officialRoute,
        public bool $trialScenarioAvailable,
        public bool $requestDocumented,
        public bool $responseDocumented,
    ) {}

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'action_key' => $this->actionKey,
            'label' => $this->label,
            'operation_class' => $this->operationClass->value,
            'params_schema' => $this->paramsSchema,
            'result_kind' => $this->resultKind->value,
            'document_policy' => $this->documentPolicy->value,
            'available' => $this->available,
            'official_state' => $this->officialState,
            'source_label' => $this->sourceLabel,
            'async' => $this->async,
            'output_fields' => $this->outputFields,
            'trial_scenario_available' => $this->trialScenarioAvailable,
            'request_documented' => $this->requestDocumented,
            'response_documented' => $this->responseDocumented,
        ];
    }

    /**
     * Forma plana transitória consumida pelo painel de cobertura legado.
     * Coordenadas e metadados de execução internos permanecem ausentes.
     *
     * @return array<string, mixed>
     */
    public function toCoverageCompatibilityArray(): array
    {
        return [
            'action_key' => $this->actionKey,
            'label' => $this->label,
            'route' => $this->officialRoute,
            'official_state' => $this->officialState,
            'is_mutating' => $this->operationClass !== FiscalOperationClass::Read,
            'trial_scenario_available' => $this->trialScenarioAvailable,
            'request_documented' => $this->requestDocumented,
            'response_documented' => $this->responseDocumented,
            'output_fields' => $this->outputFields,
        ];
    }
}
