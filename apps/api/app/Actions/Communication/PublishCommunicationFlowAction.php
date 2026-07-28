<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationFlowPublicationData;
use App\DTO\Communication\CommunicationFlowPublicationResult;
use App\Exceptions\CommunicationFlowApiException;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowDraft;
use App\Models\CommunicationFlowInboxBinding;
use App\Models\CommunicationFlowVersion;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Flows\CommunicationFlowAvailability;
use App\Services\Communication\Flows\CommunicationFlowGraphValidator;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class PublishCommunicationFlowAction
{
    private const EMPTY_GRAPH = ['nodes' => [], 'edges' => []];

    public function __construct(
        private CurrentTenant $currentTenant,
        private CommunicationFlowAvailability $availability,
        private CommunicationFlowGraphValidator $validator,
        private CommunicationEventRecorder $events,
    ) {}

    public function execute(
        CommunicationFlow $flow,
        CommunicationFlowPublicationData $data,
    ): CommunicationFlowPublicationResult {
        if (! $this->availability->enabled()) {
            throw CommunicationFlowApiException::disabled();
        }
        $membershipId = $this->currentTenant->realMembership()?->id;

        return DB::transaction(function () use ($flow, $data, $membershipId): CommunicationFlowPublicationResult {
            $draft = CommunicationFlowDraft::query()
                ->where('flow_id', $flow->id)
                ->lockForUpdate()
                ->first();
            if ($draft === null || (int) $draft->lock_version !== $data->lockVersion) {
                throw CommunicationFlowApiException::draftVersionConflict();
            }
            /** @var array{nodes: list<mixed>, edges: list<mixed>} $graph */
            $graph = is_array($draft->graph_encrypted)
                ? $draft->graph_encrypted
                : self::EMPTY_GRAPH;
            $validation = $this->validator->validate($graph, (int) $flow->tenant_id);
            if (! $validation->valid) {
                throw CommunicationFlowApiException::invalidGraph(
                    $validation->errors,
                    $validation->digest,
                );
            }

            $nextVersion = (int) CommunicationFlowVersion::query()
                ->where('flow_id', $flow->id)
                ->max('version') + 1;
            $version = CommunicationFlowVersion::query()->create([
                'tenant_id' => $flow->tenant_id,
                'flow_id' => $flow->id,
                'version' => $nextVersion,
                'graph_encrypted' => $graph,
                'graph_digest' => $validation->digest,
                'published_at' => now(),
                'published_by_membership_id' => $membershipId,
            ]);
            $enabledBindings = CommunicationFlowInboxBinding::query()
                ->where('flow_id', $flow->id)
                ->where('enabled', true)
                ->count();
            $this->events->record((int) $flow->tenant_id, 'COMMUNICATION_FLOW_PUBLISHED', [
                'flow_id' => (int) $flow->id,
                'version_id' => (int) $version->id,
                'version' => (int) $version->version,
                'graph_digest' => $version->graph_digest,
                'enabled_bindings' => $enabledBindings,
            ], actorMembershipId: $membershipId);

            return new CommunicationFlowPublicationResult(
                version: $version,
                flow: $flow->fresh() ?? $flow,
                enabledBindings: $enabledBindings,
            );
        });
    }
}
