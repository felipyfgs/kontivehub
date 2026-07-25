<?php

namespace App\Services\Fiscal\ManualConsult;

use App\Enums\ManualConsultEligibility;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\Office;
use App\Models\User;
use App\Services\FiscalMonitoring\Projection\MonitoringQueryProjectionFactory;

/**
 * Inventário tenant-scoped de consultas manuais + elegibilidade sanitizada.
 * GET nunca dispara SERPRO.
 */
final class ManualConsultInventoryService
{
    public function __construct(
        private readonly ManualConsultActionCatalog $catalog,
        private readonly ManualConsultEligibilityGate $eligibility,
        private readonly MonitoringQueryProjectionFactory $projectionFactory,
        private readonly ManualConsultReadPolicy $readPolicy,
    ) {}

    /**
     * @return array{
     *   actions: list<array<string, mixed>>,
     *   meta: array{total: int, ready: int, client_id: int|null, serpro_called: false}
     * }
     */
    public function inventory(
        Office $office,
        ?Client $client = null,
        ?string $surfaceKey = null,
        ?string $moduleKey = null,
        ?User $actor = null,
    ): array {
        $environment = $this->eligibility->environment();
        $auth = $this->eligibility->authorizationFor($office, $environment);
        $hasToken = $this->eligibility->hasUsableToken($auth);

        $actions = [];
        $ready = 0;
        $canTrigger = $actor instanceof User
            && $this->readPolicy->canTrigger($office, $client, $actor);

        foreach ($this->catalog->all() as $def) {
            if ($surfaceKey !== null && $surfaceKey !== '' && $def->surfaceKey !== $surfaceKey) {
                continue;
            }
            if ($moduleKey !== null && $moduleKey !== '' && $def->moduleKey !== $moduleKey) {
                continue;
            }

            $status = $this->eligibility->evaluateWithContext(
                $office,
                $def,
                $hasToken,
                $client,
                $auth,
                $environment,
            );
            if ($status === ManualConsultEligibility::Ready && ! $canTrigger) {
                $status = ManualConsultEligibility::PermissionDenied;
            }
            if ($status === ManualConsultEligibility::Ready) {
                $ready++;
            }

            $actions[] = $this->toPublicAction($def, $status, $office, $client);
        }

        return [
            'actions' => $actions,
            'meta' => [
                'total' => count($actions),
                'ready' => $ready,
                'client_id' => $client?->id,
                'serpro_called' => false,
            ],
        ];
    }

    public function evaluateFor(
        Office $office,
        ManualConsultActionDefinition $def,
        ?Client $client = null,
    ): ManualConsultEligibility {
        return $this->eligibility->evaluate($office, $def, $client);
    }

    /**
     * @return array<string, mixed>
     */
    private function toPublicAction(
        ManualConsultActionDefinition $def,
        ManualConsultEligibility $eligibility,
        Office $office,
        ?Client $client,
    ): array {
        return [
            'action_id' => $def->actionId,
            'label' => $def->label,
            'surface_key' => $def->surfaceKey,
            'module_key' => $def->moduleKey,
            'module_route' => $def->moduleRoute,
            'eligibility' => $eligibility->value,
            'eligibility_label' => $eligibility->label(),
            'executable' => $eligibility->isExecutable(),
            'async' => $def->async,
            'params_schema' => $def->paramsSchema,
            'last_result_summary' => $client !== null
                ? $this->lastResultSummary($office, $client, $def)
                : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function lastResultSummary(
        Office $office,
        Client $client,
        ManualConsultActionDefinition $def,
    ): ?array {
        $codes = $def->runCodes;
        if ($codes === null) {
            return null;
        }

        $q = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('system_code', $codes['system'])
            ->where('service_code', $codes['service']);
        if (($codes['operation'] ?? '') !== '') {
            $q->where('operation_code', $codes['operation']);
        }
        $run = $q->orderByDesc('id')->first();

        $snapshotQuery = FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('system_code', $codes['system'])
            ->where('service_code', $codes['service']);
        if (($codes['operation'] ?? '') !== '') {
            $snapshotQuery->where('operation_code', $codes['operation']);
        }
        $lastSnapshot = $snapshotQuery
            ->where('is_current', true)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();

        if ($run === null && $lastSnapshot === null) {
            return null;
        }

        return $this->projectionFactory->fromModels($run, $lastSnapshot)->toArray();
    }
}
