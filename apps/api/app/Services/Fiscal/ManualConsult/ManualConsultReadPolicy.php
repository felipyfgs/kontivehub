<?php

namespace App\Services\Fiscal\ManualConsult;

use App\Enums\FiscalOperationClass;
use App\Enums\ManualConsultEligibility;
use App\Enums\SerproOfficialState;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\TenantAuthorization;
use App\Services\FiscalMonitoring\Surfaces\MonitoringActionContract;
use App\Services\FiscalMonitoring\Surfaces\MonitoringSurfaceRegistry;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Fronteira fail-closed do workspace: somente actions READ canônicas e elegíveis.
 * A mesma política é aplicada no request e novamente no worker.
 */
final class ManualConsultReadPolicy
{
    public function __construct(
        private readonly MonitoringSurfaceRegistry $surfaces,
        private readonly ManualConsultActionCatalog $catalog,
        private readonly ManualConsultEligibilityGate $eligibility,
        private readonly CurrentTenant $currentTenant,
        private readonly TenantAuthorization $authorization,
        private readonly AuditLogger $audit,
    ) {}

    public function authorizeDispatch(
        Tenant $tenant,
        Client $client,
        string $actionId,
        ?int $actorUserId,
    ): ManualConsultActionDefinition {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            $this->reject(
                reasonCode: 'MANUAL_CLIENT_CROSS_TENANT',
                statusCode: 404,
                message: 'Cliente não encontrado no escritório atual.',
                actionId: $actionId,
                tenantId: (int) $tenant->id,
                userId: $actorUserId,
                subject: $client,
                boundary: 'dispatcher',
            );
        }

        $contract = $this->assertReadContract(
            actionId: $actionId,
            tenantId: (int) $tenant->id,
            userId: $actorUserId,
            subject: $client,
            boundary: 'dispatcher',
        );

        $actor = $actorUserId !== null
            ? User::query()->find($actorUserId)
            : null;
        if (! $actor instanceof User || ! $this->canTrigger($tenant, $client, $actor)) {
            $this->reject(
                reasonCode: 'MANUAL_ROLE_DENIED',
                statusCode: 403,
                message: 'Sem permissão para executar consultas fiscais.',
                actionId: $actionId,
                operationClass: $contract->operationClass,
                tenantId: (int) $tenant->id,
                userId: $actorUserId,
                subject: $client,
                boundary: 'dispatcher',
            );
        }

        return $this->catalog->get($actionId);
    }

    public function assertDispatchReady(
        Tenant $tenant,
        Client $client,
        ManualConsultActionDefinition $definition,
        ?int $actorUserId,
    ): void {
        $eligibility = $this->eligibility->evaluate($tenant, $definition, $client);
        if ($eligibility === ManualConsultEligibility::Ready) {
            return;
        }

        $this->auditRejection(
            actionId: $definition->actionId,
            reasonCode: 'MANUAL_'.strtoupper($eligibility->value),
            operationClass: FiscalOperationClass::Read,
            tenantId: (int) $tenant->id,
            userId: $actorUserId,
            subject: $client,
            boundary: 'dispatcher',
        );

        throw new ManualConsultNotReadyException($eligibility);
    }

    public function canTrigger(Tenant $tenant, ?Client $client, User $actor): bool
    {
        if (! $actor->is_active) {
            return false;
        }

        $resolved = $this->currentTenant->resolve($actor);
        if ($resolved === null || (int) $resolved->id !== (int) $tenant->id) {
            return false;
        }

        $membership = $this->currentTenant->realMembership();
        if ($membership === null
            || ! $membership->is_active
            || (int) $membership->tenant_id !== (int) $tenant->id
            || (int) $membership->user_id !== (int) $actor->id
            || ! in_array($membership->role, [TenantRole::TenantAdmin, TenantRole::TenantUser], true)
        ) {
            return false;
        }

        return $this->authorization->allows(
            $actor,
            TenantPermission::FiscalSyncTrigger,
            $client,
        );
    }

    public function assertRunMayExecute(FiscalMonitoringRun $run): void
    {
        $progress = is_array($run->progress) ? $run->progress : [];
        if (($progress['manual_consult'] ?? false) !== true) {
            return;
        }

        $actionId = is_string($progress['action_id'] ?? null)
            ? trim($progress['action_id'])
            : '';
        $tenant = Tenant::query()->find($run->tenant_id);
        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $run->tenant_id)
            ->whereKey($run->client_id)
            ->first();

        if ($tenant === null || $client === null) {
            $this->reject(
                reasonCode: 'MANUAL_TENANT_CONTEXT_MISSING',
                statusCode: 403,
                message: 'Contexto da consulta indisponível.',
                actionId: $actionId,
                tenantId: (int) $run->tenant_id,
                userId: $run->triggered_by !== null ? (int) $run->triggered_by : null,
                subject: $run,
                boundary: 'job',
            );
        }

        $this->assertQueuedExecution(
            tenant: $tenant,
            client: $client,
            actionId: $actionId,
            actorUserId: $run->triggered_by !== null ? (int) $run->triggered_by : null,
            subject: $run,
            run: $run,
        );
    }

    public function assertAsyncJobMayExecute(
        Tenant $tenant,
        Client $client,
        string $actionId,
        ?int $actorUserId,
        Model $subject,
    ): void {
        $this->assertQueuedExecution(
            tenant: $tenant,
            client: $client,
            actionId: $actionId,
            actorUserId: $actorUserId,
            subject: $subject,
        );
    }

    private function assertQueuedExecution(
        Tenant $tenant,
        Client $client,
        string $actionId,
        ?int $actorUserId,
        Model $subject,
        ?FiscalMonitoringRun $run = null,
    ): void {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            $this->rejectQueued('MANUAL_CLIENT_CROSS_TENANT', $actionId, $tenant, $actorUserId, $subject);
        }

        $contract = $this->assertReadContract(
            actionId: $actionId,
            tenantId: (int) $tenant->id,
            userId: $actorUserId,
            subject: $subject,
            boundary: 'job',
        );
        $definition = $this->catalog->get($actionId);

        if ($run !== null && ! $this->runCoordinatesMatch($run, $definition)) {
            $this->rejectQueued(
                'MANUAL_RUN_COORDINATES_MISMATCH',
                $actionId,
                $tenant,
                $actorUserId,
                $subject,
                $contract->operationClass,
            );
        }

        $actor = $actorUserId !== null
            ? User::query()->find($actorUserId)
            : null;
        $membership = $actor instanceof User
            ? TenantMembership::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $actor->id)
                ->where('is_active', true)
                ->with(['tenant', 'permissionProfile'])
                ->first()
            : null;

        if (! $actor instanceof User
            || ! $actor->is_active
            || $membership === null
            || ! in_array($membership->role, [TenantRole::TenantAdmin, TenantRole::TenantUser], true)
        ) {
            $this->rejectQueued(
                'MANUAL_ROLE_DENIED',
                $actionId,
                $tenant,
                $actorUserId,
                $subject,
                $contract->operationClass,
            );
        }

        $this->currentTenant->clear();
        try {
            $this->currentTenant->bind($actor, $membership);
            if (! $this->authorization->allows(
                $actor,
                TenantPermission::FiscalSyncTrigger,
                $client,
            )) {
                $this->rejectQueued(
                    'MANUAL_ROLE_DENIED',
                    $actionId,
                    $tenant,
                    $actorUserId,
                    $subject,
                    $contract->operationClass,
                );
            }

            $eligibility = $this->eligibility->evaluate($tenant, $definition, $client);
            if ($eligibility !== ManualConsultEligibility::Ready) {
                $this->rejectQueued(
                    'MANUAL_'.strtoupper($eligibility->value),
                    $actionId,
                    $tenant,
                    $actorUserId,
                    $subject,
                    $contract->operationClass,
                );
            }
        } finally {
            $this->currentTenant->clear();
        }
    }

    private function assertReadContract(
        string $actionId,
        int $tenantId,
        ?int $userId,
        Model $subject,
        string $boundary,
    ): MonitoringActionContract {
        $contract = $this->findContract($actionId);
        if ($contract === null) {
            $this->reject(
                reasonCode: 'MANUAL_ACTION_UNKNOWN',
                statusCode: 404,
                message: 'Ação de consulta manual desconhecida.',
                actionId: $actionId,
                tenantId: $tenantId,
                userId: $userId,
                subject: $subject,
                boundary: $boundary,
            );
        }

        if ($contract->operationClass !== FiscalOperationClass::Read) {
            $this->reject(
                reasonCode: 'MANUAL_OPERATION_NOT_READ',
                statusCode: 422,
                message: ManualConsultEligibility::MutatingBlocked->label(),
                actionId: $actionId,
                operationClass: $contract->operationClass,
                tenantId: $tenantId,
                userId: $userId,
                subject: $subject,
                boundary: $boundary,
            );
        }

        if ($contract->officialState !== SerproOfficialState::Production->value) {
            $this->reject(
                reasonCode: 'MANUAL_ACTION_NOT_PRODUCTION',
                statusCode: 422,
                message: 'Operação não está em PRODUCTION.',
                actionId: $actionId,
                operationClass: $contract->operationClass,
                tenantId: $tenantId,
                userId: $userId,
                subject: $subject,
                boundary: $boundary,
            );
        }

        if (! $contract->available || ! $this->catalog->has($actionId)) {
            $this->reject(
                reasonCode: 'MANUAL_ADAPTER_MISSING',
                statusCode: 422,
                message: ManualConsultEligibility::AdapterMissing->label(),
                actionId: $actionId,
                operationClass: $contract->operationClass,
                tenantId: $tenantId,
                userId: $userId,
                subject: $subject,
                boundary: $boundary,
            );
        }

        return $contract;
    }

    private function findContract(string $actionId): ?MonitoringActionContract
    {
        [$surfaceKey, $actionKey] = array_pad(explode(':', $actionId, 2), 2, null);
        if (! is_string($actionKey) || $actionKey === '' || ! $this->surfaces->has($surfaceKey)) {
            return null;
        }

        foreach ($this->surfaces->get($surfaceKey)->capabilities() as $capability) {
            foreach ($capability->actions as $action) {
                if ($action->actionKey === $actionKey) {
                    return $action;
                }
            }
        }

        return null;
    }

    private function runCoordinatesMatch(
        FiscalMonitoringRun $run,
        ManualConsultActionDefinition $definition,
    ): bool {
        if ($definition->runCodes === null) {
            return true;
        }

        return strtoupper((string) $run->system_code) === strtoupper($definition->runCodes['system'])
            && strtoupper((string) $run->service_code) === strtoupper($definition->runCodes['service'])
            && strtoupper((string) $run->operation_code) === strtoupper($definition->runCodes['operation']);
    }

    private function rejectQueued(
        string $reasonCode,
        string $actionId,
        Tenant $tenant,
        ?int $userId,
        Model $subject,
        FiscalOperationClass $operationClass = FiscalOperationClass::Read,
    ): never {
        $this->reject(
            reasonCode: $reasonCode,
            statusCode: 403,
            message: 'A consulta foi bloqueada por política consultiva.',
            actionId: $actionId,
            operationClass: $operationClass,
            tenantId: (int) $tenant->id,
            userId: $userId,
            subject: $subject,
            boundary: 'job',
        );
    }

    private function reject(
        string $reasonCode,
        int $statusCode,
        string $message,
        string $actionId,
        int $tenantId,
        ?int $userId,
        Model $subject,
        string $boundary,
        ?FiscalOperationClass $operationClass = null,
    ): never {
        $this->auditRejection(
            actionId: $actionId,
            reasonCode: $reasonCode,
            operationClass: $operationClass,
            tenantId: $tenantId,
            userId: $userId,
            subject: $subject,
            boundary: $boundary,
        );

        throw new ManualConsultReadPolicyException($reasonCode, $statusCode, $message);
    }

    private function auditRejection(
        string $actionId,
        string $reasonCode,
        ?FiscalOperationClass $operationClass,
        int $tenantId,
        ?int $userId,
        Model $subject,
        string $boundary,
    ): void {
        $this->audit->record(
            action: 'fiscal.monitoring.read_rejected',
            result: 'DENIED',
            subject: $subject,
            context: array_filter([
                'action_id' => $actionId !== '' ? $actionId : null,
                'reason_code' => $reasonCode,
                'operation_class' => $operationClass?->value,
                'boundary' => $boundary,
            ], static fn (mixed $value): bool => $value !== null),
            userId: $userId,
            tenantId: $tenantId,
        );
    }
}
