<?php

namespace App\Actions\Communication;

use App\DTO\Communication\FlowGraphInputData;
use App\Exceptions\CommunicationFlowApiException;
use App\Models\CommunicationFlow;
use App\Services\Communication\Events\EventRecorder;
use App\Services\Communication\Flows\FlowAvailability;
use App\Services\Communication\Flows\FlowDryRunResult;
use App\Services\Communication\Flows\FlowDryRunService;
use App\Services\Communication\Flows\FlowGraphPreviewService;
use App\Services\Communication\Flows\FlowGraphValidationResult;
use App\Services\Communication\Flows\FlowGraphValidator;
use App\Support\CurrentTenant;
use App\Support\LogSanitizer;
use Illuminate\Support\Facades\Log;

final readonly class InspectFlowGraphAction
{
    private const EMPTY_GRAPH = ['nodes' => [], 'edges' => []];

    public function __construct(
        private CurrentTenant $currentTenant,
        private FlowAvailability $availability,
        private FlowGraphValidator $validator,
        private FlowDryRunService $dryRun,
        private FlowGraphPreviewService $preview,
        private EventRecorder $events,
    ) {}

    public function validate(
        CommunicationFlow $flow,
        FlowGraphInputData $data,
    ): FlowGraphValidationResult {
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
        FlowGraphInputData $data,
    ): FlowDryRunResult {
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
        FlowGraphInputData $data,
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
        FlowGraphInputData $data,
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
