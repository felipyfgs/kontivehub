<?php

namespace App\Actions\Communication;

use App\Exceptions\CommunicationFlowApiException;
use App\Models\CommunicationFlow;
use App\Services\Communication\Events\EventRecorder;
use App\Services\Communication\Flows\FlowAvailability;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class DeleteFlowAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private FlowAvailability $availability,
        private EventRecorder $events,
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
