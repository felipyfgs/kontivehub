<?php

namespace App\Services\Fiscal\ManualConsult;

use App\Enums\FiscalOperationClass;
use App\Enums\SerproOfficialState;
use App\Services\FiscalMonitoring\Surfaces\MonitoringSurfaceRegistry;
use InvalidArgumentException;

/**
 * Inventário canônico de ações de consulta manual (onda 1).
 *
 * Fonte exclusiva: MonitoringSurfaceRegistry ∩ catálogo
 * (PRODUCTION + IMPLEMENTED + !mutating).
 * Handlers ausentes ficam com hasHandler=false → elegibilidade adapter_missing.
 */
final class ManualConsultActionCatalog
{
    /** @var array<string, ManualConsultActionDefinition>|null */
    private ?array $byActionId = null;

    public function __construct(
        private readonly MonitoringSurfaceRegistry $surfaces,
    ) {}

    /**
     * @return list<ManualConsultActionDefinition>
     */
    public function all(): array
    {
        return array_values($this->ensureLoaded());
    }

    public function get(string $actionId): ManualConsultActionDefinition
    {
        $all = $this->ensureLoaded();
        if (! isset($all[$actionId])) {
            throw new InvalidArgumentException("action_id desconhecida: {$actionId}");
        }

        return $all[$actionId];
    }

    public function has(string $actionId): bool
    {
        return isset($this->ensureLoaded()[$actionId]);
    }

    public function findByOperationKey(string $operationKey): ?ManualConsultActionDefinition
    {
        foreach ($this->ensureLoaded() as $def) {
            if ($def->operationKey === $operationKey) {
                return $def;
            }
        }

        return null;
    }

    /**
     * @return array<string, ManualConsultActionDefinition>
     */
    private function ensureLoaded(): array
    {
        if ($this->byActionId !== null) {
            return $this->byActionId;
        }

        /** @var array<string, ManualConsultActionDefinition> $map */
        $map = [];
        foreach ($this->surfaces->all() as $surface) {
            foreach ($surface->capabilities() as $capability) {
                foreach ($capability->actions as $action) {
                    if ($action->operationClass !== FiscalOperationClass::Read
                        || $action->officialState !== SerproOfficialState::Production->value
                    ) {
                        continue;
                    }

                    $actionId = $surface->surfaceKey.':'.$action->actionKey;
                    $map[$actionId] = new ManualConsultActionDefinition(
                        actionId: $actionId,
                        operationKey: $action->operationKey,
                        label: $action->label,
                        surfaceKey: $surface->surfaceKey,
                        moduleKey: $action->moduleKey,
                        featureModule: $action->featureModule,
                        handler: $action->handler,
                        hasHandler: $action->available,
                        paramsSchema: $action->paramsSchema,
                        requiredProxyPowers: $action->requiredProxyPowers,
                        runCodes: $action->runCodes,
                        moduleRoute: $surface->routePattern,
                        async: $action->async,
                    );
                }
            }
        }

        ksort($map);
        $this->byActionId = $map;

        return $this->byActionId;
    }
}
