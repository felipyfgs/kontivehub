<?php

namespace App\Services\Fiscal\ManualConsult;

/**
 * Definição interna de uma ação de consulta manual.
 * operation_key permanece server-side; o público recebe action_id + labels.
 *
 * @phpstan-type ParamField array{
 *   name: string,
 *   type: string,
 *   required: bool,
 *   label: string,
 *   pattern?: string|null
 * }
 */
final readonly class ManualConsultActionDefinition
{
    /**
     * @param  list<ParamField>  $paramsSchema
     * @param  list<string>  $requiredProxyPowers
     * @param  array{system: string, service: string, operation: string}|null  $runCodes
     */
    public function __construct(
        public string $actionId,
        public string $operationKey,
        public string $label,
        public string $surfaceKey,
        public string $moduleKey,
        public ?string $featureModule,
        public string $handler,
        public bool $hasHandler,
        public array $paramsSchema = [],
        public array $requiredProxyPowers = [],
        public ?array $runCodes = null,
        public string $moduleRoute = '/monitoring',
        public bool $async = false,
    ) {}
}
