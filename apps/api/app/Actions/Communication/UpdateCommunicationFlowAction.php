<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationFlowUpdateData;
use App\Enums\Communication\FlowStatus;
use App\Exceptions\CommunicationFlowApiException;
use App\Models\CommunicationFlow;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Flows\CommunicationFlowAvailability;
use App\Services\Communication\Flows\CommunicationFlowRunControlService;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCommunicationFlowAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private CommunicationFlowAvailability $availability,
        private CommunicationFlowRunControlService $runControl,
        private CommunicationEventRecorder $events,
    ) {}

    public function execute(
        CommunicationFlow $flow,
        CommunicationFlowUpdateData $data,
    ): CommunicationFlow {
        $this->ensureEnabled();
        $membershipId = $this->currentTenant->realMembership()?->id;

        try {
            return DB::transaction(function () use ($flow, $data, $membershipId): CommunicationFlow {
                $fresh = CommunicationFlow::query()
                    ->whereKey($flow->id)
                    ->lockForUpdate()
                    ->first();
                if ($fresh === null || (int) $fresh->lock_version !== $data->lockVersion) {
                    throw CommunicationFlowApiException::flowVersionConflict();
                }

                $previousStatus = $fresh->status instanceof FlowStatus
                    ? $fresh->status
                    : FlowStatus::from((string) $fresh->status);
                if ($data->name !== null) {
                    $fresh->name = $data->name;
                }
                if ($data->status !== null) {
                    $fresh->status = $data->status;
                }
                $fresh->lock_version = $data->lockVersion + 1;
                $fresh->save();

                if ($fresh->status === FlowStatus::Paused && $previousStatus !== FlowStatus::Paused) {
                    $this->runControl->stopActiveForFlow((int) $fresh->id, 'flow_paused');
                }

                $this->events->record((int) $fresh->tenant_id, 'COMMUNICATION_FLOW_UPDATED', [
                    'flow_id' => (int) $fresh->id,
                    'status' => $fresh->status->value,
                    'lock_version' => (int) $fresh->lock_version,
                ], actorMembershipId: $membershipId);

                return $fresh;
            });
        } catch (QueryException $error) {
            if ($this->isFlowNameConflict($error)) {
                throw CommunicationFlowApiException::flowNameConflict();
            }

            throw $error;
        }
    }

    private function ensureEnabled(): void
    {
        if (! $this->availability->enabled()) {
            throw CommunicationFlowApiException::disabled();
        }
    }

    private function isFlowNameConflict(QueryException $error): bool
    {
        return (string) $error->getCode() === '23505'
            && str_contains(
                mb_strtolower($error->getMessage()),
                'communication_flows_tenant_id_name_unique',
            );
    }
}
