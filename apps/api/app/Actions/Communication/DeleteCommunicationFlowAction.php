<?php

namespace App\Actions\Communication;

use App\Exceptions\CommunicationFlowApiException;
use App\Models\CommunicationFlow;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Flows\CommunicationFlowAvailability;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class DeleteCommunicationFlowAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private CommunicationFlowAvailability $availability,
        private CommunicationEventRecorder $events,
    ) {}

    public function execute(CommunicationFlow $flow): void
    {
        if (! $this->availability->enabled()) {
            throw CommunicationFlowApiException::disabled();
        }
        $membershipId = $this->currentTenant->realMembership()?->id;

        DB::transaction(function () use ($flow, $membershipId): void {
            $locked = CommunicationFlow::query()
                ->whereKey($flow->id)
                ->lockForUpdate()
                ->firstOrFail();
            $tenantId = (int) $locked->tenant_id;
            $flowId = (int) $locked->id;
            $locked->delete();
            $this->events->record($tenantId, 'COMMUNICATION_FLOW_DELETED', [
                'flow_id' => $flowId,
            ], actorMembershipId: $membershipId);
        });
    }
}
