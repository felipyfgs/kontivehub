<?php

namespace App\Actions\Communication;

use App\DTO\Communication\AutomationPolicyData;
use App\Exceptions\CommunicationAutomationApiException;
use App\Models\CommunicationAutomationPolicy;
use App\Services\Communication\Events\EventRecorder;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class UpsertAutomationPolicyAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private EventRecorder $events,
    ) {}

    public function handle(AutomationPolicyData $data): CommunicationAutomationPolicy
    {
        try {
            return DB::transaction(function () use ($data): CommunicationAutomationPolicy {
                $tenant = $this->currentTenant->tenant();
                $policy = CommunicationAutomationPolicy::query()
                    ->where('module_key', $data->moduleKey)
                    ->where('submodule_key', $data->submoduleKey)
                    ->lockForUpdate()
                    ->first();

                if ($policy === null) {
                    if ($data->lockVersion !== 0) {
                        throw CommunicationAutomationApiException::policyVersionConflict();
                    }

                    $policy = CommunicationAutomationPolicy::query()->create([
                        ...$data->persistenceAttributes(),
                        'tenant_id' => $tenant->id,
                        'lock_version' => 1,
                    ]);
                } else {
                    if ((int) $policy->lock_version !== $data->lockVersion) {
                        throw CommunicationAutomationApiException::policyVersionConflict();
                    }

                    $policy->forceFill([
                        ...$data->persistenceAttributes(),
                        'lock_version' => (int) $policy->lock_version + 1,
                    ])->save();
                    $policy->refresh();
                }

                $this->events->record((int) $tenant->id, 'AUTOMATION_POLICY_UPDATED', [
                    'policy_id' => (int) $policy->id,
                    'module_key' => $policy->module_key,
                    'submodule_key' => $policy->submodule_key,
                    'enabled' => (bool) $policy->is_enabled,
                    'lock_version' => (int) $policy->lock_version,
                ], inboxId: $policy->inbox_id !== null ? (int) $policy->inbox_id : null,
                    actorMembershipId: $this->currentTenant->realMembership()?->id);

                return $policy->load('inbox');
            });
        } catch (QueryException $error) {
            $databaseMessage = (string) ($error->errorInfo[2] ?? '');
            if ($error->getCode() === '23505'
                && str_contains(
                    $databaseMessage,
                    'communication_automation_policies_tenant_id_module_a9d6014d6d',
                )) {
                throw CommunicationAutomationApiException::policyVersionConflict();
            }

            throw $error;
        }
    }
}
