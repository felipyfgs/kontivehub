<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationFlowCloneData;
use App\Enums\Communication\FlowStatus;
use App\Exceptions\CommunicationFlowApiException;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowDraft;
use App\Models\CommunicationFlowVersion;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Flows\CommunicationFlowAvailability;
use App\Services\Communication\Flows\CommunicationFlowGraphCanonicalizer;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class CloneCommunicationFlowAction
{
    private const EMPTY_GRAPH = ['nodes' => [], 'edges' => []];

    public function __construct(
        private CurrentTenant $currentTenant,
        private CommunicationFlowAvailability $availability,
        private CommunicationFlowGraphCanonicalizer $canonicalizer,
        private CommunicationEventRecorder $events,
    ) {}

    public function execute(
        CommunicationFlow $source,
        CommunicationFlowCloneData $data,
    ): CommunicationFlow {
        if (! $this->availability->enabled()) {
            throw CommunicationFlowApiException::disabled();
        }
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $membershipId = $this->currentTenant->realMembership()?->id;
        $graph = $this->sourceGraph($source, $data->fromVersionId);

        try {
            return DB::transaction(function () use ($source, $data, $tenantId, $membershipId, $graph): CommunicationFlow {
                $clone = CommunicationFlow::query()->create([
                    'tenant_id' => $tenantId,
                    'name' => $data->name,
                    'status' => FlowStatus::Paused,
                    'lock_version' => 1,
                    'created_by_membership_id' => $membershipId,
                ]);
                CommunicationFlowDraft::query()->create([
                    'tenant_id' => $tenantId,
                    'flow_id' => $clone->id,
                    'graph_encrypted' => $graph,
                    'graph_digest' => $this->canonicalizer->digest($graph),
                    'lock_version' => 1,
                    'updated_by_membership_id' => $membershipId,
                ]);
                $this->events->record($tenantId, 'COMMUNICATION_FLOW_CLONED', [
                    'flow_id' => (int) $clone->id,
                    'source_flow_id' => (int) $source->id,
                    'from_version_id' => $data->fromVersionId,
                ], actorMembershipId: $membershipId);

                return $clone;
            });
        } catch (QueryException $error) {
            if ($this->isFlowNameConflict($error)) {
                throw CommunicationFlowApiException::flowNameConflict();
            }

            throw $error;
        }
    }

    /** @return array{nodes: list<mixed>, edges: list<mixed>} */
    private function sourceGraph(
        CommunicationFlow $source,
        ?int $versionId,
    ): array {
        if ($versionId !== null) {
            $version = CommunicationFlowVersion::query()
                ->where('flow_id', $source->id)
                ->whereKey($versionId)
                ->first();
            if ($version === null) {
                throw CommunicationFlowApiException::invalidPublishedVersion();
            }

            return is_array($version->graph_encrypted)
                ? $version->graph_encrypted
                : self::EMPTY_GRAPH;
        }

        $draft = $source->draft()->first();

        return $draft !== null && is_array($draft->graph_encrypted)
            ? $draft->graph_encrypted
            : self::EMPTY_GRAPH;
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
