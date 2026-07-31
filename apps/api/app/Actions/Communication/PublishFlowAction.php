<?php

namespace App\Actions\Communication;

use App\DTO\Communication\FlowPublicationData;
use App\DTO\Communication\FlowPublicationResult;
use App\Exceptions\CommunicationFlowApiException;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowDraft;
use App\Models\CommunicationFlowInboxBinding;
use App\Models\CommunicationFlowVersion;
use App\Services\Communication\Events\EventRecorder;
use App\Services\Communication\Flows\FlowAvailability;
use App\Services\Communication\Flows\FlowGraphValidator;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class PublishFlowAction
{
    private const EMPTY_GRAPH = ['nodes' => [], 'edges' => []];

    public function __construct(
        private CurrentTenant $currentTenant,
        private FlowAvailability $availability,
        private FlowGraphValidator $validator,
        private EventRecorder $events,
    ) {}

    public function execute(
        CommunicationFlow $flow,
        FlowPublicationData $data,
    ): FlowPublicationResult {
        if (! $this->availability->enabled()) {
            throw CommunicationFlowApiException::disabled();
        }
        $membershipId = $this->currentTenant->realMembership()?->id;

        return DB::transaction(function () use ($flow, $data, $membershipId): FlowPublicationResult {
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

            return new FlowPublicationResult(
                version: $version,
                flow: $flow->fresh() ?? $flow,
                enabledBindings: $enabledBindings,
            );
        });
    }
}
