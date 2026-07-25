<?php

namespace App\Services\FiscalMonitoring\Surfaces;

/**
 * Cápsula funcional pública de uma superfície do monitor.
 *
 * As actions preservam apenas identificadores internos no backend. A projeção
 * pública expõe metadados semânticos e nunca coordenadas do provider.
 */
final readonly class MonitoringCapabilityContract
{
    /**
     * @param  list<MonitoringActionContract>  $actions
     */
    public function __construct(
        public string $capabilityKey,
        public string $label,
        public array $actions,
    ) {}

    /** @return list<string> */
    public function operationKeys(): array
    {
        return array_map(
            static fn (MonitoringActionContract $action): string => $action->operationKey,
            $this->actions,
        );
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'capability_key' => $this->capabilityKey,
            'label' => $this->label,
            'actions_total' => count($this->actions),
            'available_actions' => count(array_filter(
                $this->actions,
                static fn (MonitoringActionContract $action): bool => $action->available,
            )),
            'actions' => array_map(
                static fn (MonitoringActionContract $action): array => $action->toPublicArray(),
                $this->actions,
            ),
        ];
    }
}
