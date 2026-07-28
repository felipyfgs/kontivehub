<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationFlowGraphInputData;
use App\Exceptions\CommunicationFlowApiException;
use App\Models\CommunicationFlow;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Flows\CommunicationFlowAvailability;
use App\Services\Communication\Flows\CommunicationFlowDryRunResult;
use App\Services\Communication\Flows\CommunicationFlowDryRunService;
use App\Services\Communication\Flows\CommunicationFlowGraphPreviewService;
use App\Services\Communication\Flows\CommunicationFlowGraphValidationResult;
use App\Services\Communication\Flows\CommunicationFlowGraphValidator;
use App\Support\CurrentTenant;
use App\Support\LogSanitizer;
use Illuminate\Support\Facades\Log;

final readonly class InspectCommunicationFlowGraphAction
{
    private const EMPTY_GRAPH = ['nodes' => [], 'edges' => []];

    public function __construct(
        private CurrentTenant $currentTenant,
        private CommunicationFlowAvailability $availability,
        private CommunicationFlowGraphValidator $validator,
        private CommunicationFlowDryRunService $dryRun,
        private CommunicationFlowGraphPreviewService $preview,
        private CommunicationEventRecorder $events,
    ) {}

    public function validate(
        CommunicationFlow $flow,
        CommunicationFlowGraphInputData $data,
    ): CommunicationFlowGraphValidationResult {
        $this->ensureEnabled();
        $result = $this->validator->validate(
            $this->graph($flow, $data),
            (int) $flow->tenant_id,
        );
        if (! $result->valid) {
            throw CommunicationFlowApiException::invalidGraph(
                $result->errors,
                $result->digest,
            );
        }

        return $result;
    }

    public function dryRun(
        CommunicationFlow $flow,
        CommunicationFlowGraphInputData $data,
    ): CommunicationFlowDryRunResult {
        $this->ensureEnabled();
        $result = $this->dryRun->simulate(
            $this->graph($flow, $data),
            (int) $flow->tenant_id,
            $data->context,
        );
        Log::info('communication.flow.dry_run', LogSanitizer::redact([
            'flow_id' => (int) $flow->id,
            'tenant_id' => (int) $flow->tenant_id,
            'graph_digest' => $result->graphDigest,
            'outcome' => $result->outcome,
            'valid' => $result->valid,
            'steps_count' => count($result->steps),
        ]));
        $this->events->record((int) $flow->tenant_id, 'COMMUNICATION_FLOW_DRY_RUN', [
            'flow_id' => (int) $flow->id,
            'graph_digest' => $result->graphDigest,
            'outcome' => $result->outcome,
            'valid' => $result->valid,
            'steps_count' => count($result->steps),
        ], actorMembershipId: $this->currentTenant->realMembership()?->id);
        if (! $result->valid) {
            throw CommunicationFlowApiException::invalidGraph(
                $result->errors,
                $result->graphDigest,
            );
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function preview(
        CommunicationFlow $flow,
        CommunicationFlowGraphInputData $data,
    ): array {
        $this->ensureEnabled();
        $preview = $this->preview->preview($this->graph($flow, $data));
        Log::info('communication.flow.preview', LogSanitizer::redact([
            'flow_id' => (int) $flow->id,
            'tenant_id' => (int) $flow->tenant_id,
            'graph_digest' => $preview['graph_digest'],
            'masked_paths_count' => count($preview['masked_paths']),
        ]));

        return $preview;
    }

    /** @return array{nodes: list<mixed>, edges: list<mixed>} */
    private function graph(
        CommunicationFlow $flow,
        CommunicationFlowGraphInputData $data,
    ): array {
        if ($data->graph !== null) {
            return $data->graph;
        }

        $draft = $flow->draft()->firstOrFail();

        return is_array($draft->graph_encrypted)
            ? $draft->graph_encrypted
            : self::EMPTY_GRAPH;
    }

    private function ensureEnabled(): void
    {
        if (! $this->availability->enabled()) {
            throw CommunicationFlowApiException::disabled();
        }
    }
}
