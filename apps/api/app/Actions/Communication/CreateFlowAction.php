<?php

namespace App\Actions\Communication;

use App\DTO\Communication\FlowCreationData;
use App\Enums\Communication\FlowStatus;
use App\Exceptions\CommunicationFlowApiException;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowDraft;
use App\Services\Communication\Events\EventRecorder;
use App\Services\Communication\Flows\FlowAvailability;
use App\Services\Communication\Flows\FlowGraphCanonicalizer;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class CreateFlowAction
{
    private const EMPTY_GRAPH = ['nodes' => [], 'edges' => []];

    public function __construct(
        private CurrentTenant $currentTenant,
        private FlowAvailability $availability,
        private FlowGraphCanonicalizer $canonicalizer,
        private EventRecorder $events,
    ) {}

    public function execute(FlowCreationData $data): CommunicationFlow
    {
        $this->ensureEnabled();
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $membershipId = $this->currentTenant->realMembership()?->id;

        try {
            return DB::transaction(function () use ($data, $tenantId, $membershipId): CommunicationFlow {
                $flow = CommunicationFlow::query()->create([
                    'tenant_id' => $tenantId,
                    'name' => $data->name,
                    'status' => FlowStatus::Paused,
                    'lock_version' => 1,
                    'created_by_membership_id' => $membershipId,
                ]);
                CommunicationFlowDraft::query()->create([
                    'tenant_id' => $tenantId,
                    'flow_id' => $flow->id,
                    'graph_encrypted' => self::EMPTY_GRAPH,
                    'graph_digest' => $this->canonicalizer->digest(self::EMPTY_GRAPH),
                    'lock_version' => 1,
                    'updated_by_membership_id' => $membershipId,
                ]);
                $this->events->record($tenantId, 'COMMUNICATION_FLOW_CREATED', [
                    'flow_id' => (int) $flow->id,
                    'name' => $flow->name,
                    'status' => $flow->status->value,
                    'lock_version' => (int) $flow->lock_version,
                ], actorMembershipId: $membershipId);

                return $flow;
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
