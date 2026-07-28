<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationFlowDraftData;
use App\Exceptions\CommunicationFlowApiException;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowDraft;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Flows\CommunicationFlowAvailability;
use App\Services\Communication\Flows\CommunicationFlowGraphCanonicalizer;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCommunicationFlowDraftAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private CommunicationFlowAvailability $availability,
        private CommunicationFlowGraphCanonicalizer $canonicalizer,
        private CommunicationEventRecorder $events,
    ) {}

    public function execute(
        CommunicationFlow $flow,
        CommunicationFlowDraftData $data,
    ): CommunicationFlowDraft {
        if (! $this->availability->enabled()) {
            throw CommunicationFlowApiException::disabled();
        }
        $digest = $this->canonicalizer->digest($data->graph);
        $membershipId = $this->currentTenant->realMembership()?->id;

        return DB::transaction(function () use ($flow, $data, $digest, $membershipId): CommunicationFlowDraft {
            $draft = CommunicationFlowDraft::query()
                ->where('flow_id', $flow->id)
                ->lockForUpdate()
                ->first();
            if ($draft === null || (int) $draft->lock_version !== $data->lockVersion) {
                throw CommunicationFlowApiException::draftVersionConflict();
            }
            $draft->fill([
                'graph_encrypted' => $data->graph,
                'graph_digest' => $digest,
                'lock_version' => $data->lockVersion + 1,
                'updated_by_membership_id' => $membershipId,
            ]);
            $draft->save();
            $this->events->record((int) $flow->tenant_id, 'COMMUNICATION_FLOW_DRAFT_UPDATED', [
                'flow_id' => (int) $flow->id,
                'draft_id' => (int) $draft->id,
                'graph_digest' => $draft->graph_digest,
                'lock_version' => (int) $draft->lock_version,
            ], actorMembershipId: $membershipId);

            return $draft;
        });
    }
}
