<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Enums\Communication\FlowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreCommunicationFlowBindingRequest;
use App\Http\Requests\Communication\StoreCommunicationFlowRequest;
use App\Http\Requests\Communication\UpdateCommunicationFlowBindingRequest;
use App\Http\Requests\Communication\UpdateCommunicationFlowDraftRequest;
use App\Http\Requests\Communication\UpdateCommunicationFlowRequest;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowDraft;
use App\Models\CommunicationFlowInboxBinding;
use App\Models\CommunicationFlowVersion;
use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Flows\CommunicationFlowAvailability;
use App\Services\Communication\Flows\CommunicationFlowDryRunService;
use App\Services\Communication\Flows\CommunicationFlowGraphCanonicalizer;
use App\Services\Communication\Flows\CommunicationFlowGraphPreviewService;
use App\Services\Communication\Flows\CommunicationFlowGraphValidator;
use App\Services\Communication\Flows\CommunicationFlowRunControlService;
use App\Support\CurrentTenant;
use App\Support\LogSanitizer;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CommunicationFlowController extends Controller
{
    private const EMPTY_GRAPH = ['nodes' => [], 'edges' => []];

    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly CurrentTenant $currentTenant,
        private readonly CommunicationFlowAvailability $flowsAvailability,
        private readonly CommunicationFlowGraphValidator $validator,
        private readonly CommunicationFlowGraphCanonicalizer $canonicalizer,
        private readonly CommunicationFlowDryRunService $dryRun,
        private readonly CommunicationFlowGraphPreviewService $graphPreview,
        private readonly CommunicationEventRecorder $events,
        private readonly CommunicationFlowRunControlService $runControl,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertViewFlows($this->actor($request));

        $items = CommunicationFlow::query()->orderBy('name')->get();

        return response()->json([
            'data' => $items->map(fn (CommunicationFlow $flow): array => $this->flowPayload($flow))->values(),
            'meta' => [
                'flows_enabled' => $this->flowsAvailability->enabled(),
            ],
        ]);
    }

    public function store(StoreCommunicationFlowRequest $request): JsonResponse
    {
        $this->denyIfFlowsDisabled();
        $this->access->assertManageFlows($this->actor($request));
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $name = trim($request->validated('name'));

        $flow = DB::transaction(function () use ($tenantId, $name): CommunicationFlow {
            $flow = CommunicationFlow::query()->create([
                'tenant_id' => $tenantId,
                'name' => $name,
                'status' => FlowStatus::Paused,
                'lock_version' => 1,
                'created_by_membership_id' => $this->currentTenant->realMembership()?->id,
            ]);
            $digest = $this->canonicalizer->digest(self::EMPTY_GRAPH);
            CommunicationFlowDraft::query()->create([
                'tenant_id' => $tenantId,
                'flow_id' => $flow->id,
                'graph_encrypted' => self::EMPTY_GRAPH,
                'graph_digest' => $digest,
                'lock_version' => 1,
                'updated_by_membership_id' => $this->currentTenant->realMembership()?->id,
            ]);

            return $flow;
        });

        $this->events->record($tenantId, 'COMMUNICATION_FLOW_CREATED', [
            'flow_id' => (int) $flow->id,
            'name' => $flow->name,
            'status' => $flow->status->value,
            'lock_version' => (int) $flow->lock_version,
        ], actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json(['data' => $this->flowPayload($flow->load('draft'))], 201);
    }

    public function show(Request $request, int $flow): JsonResponse
    {
        $model = CommunicationFlow::query()->with(['draft', 'versions', 'bindings'])->findOrFail($flow);
        $this->access->assertViewFlows($this->actor($request), $model);

        return response()->json(['data' => $this->flowPayload($model, detailed: true)]);
    }

    public function update(UpdateCommunicationFlowRequest $request, int $flow): JsonResponse
    {
        $this->denyIfFlowsDisabled();
        $model = CommunicationFlow::query()->findOrFail($flow);
        $this->access->assertManageFlows($this->actor($request), $model);
        $data = $request->validated();
        $previousStatus = $model->status instanceof FlowStatus ? $model->status : FlowStatus::from((string) $model->status);

        $updated = DB::transaction(function () use ($model, $data): ?CommunicationFlow {
            $fresh = CommunicationFlow::query()->whereKey($model->id)->lockForUpdate()->first();
            if ($fresh === null || (int) $fresh->lock_version !== (int) $data['lock_version']) {
                return null;
            }
            if (isset($data['name'])) {
                $fresh->name = trim($data['name']);
            }
            if (isset($data['status'])) {
                $fresh->status = FlowStatus::from($data['status']);
            }
            $fresh->lock_version = (int) $data['lock_version'] + 1;
            $fresh->save();

            return $fresh;
        });

        if ($updated === null) {
            return $this->versionConflict('Fluxo foi alterado por outro usuário.');
        }

        if ($updated->status === FlowStatus::Paused && $previousStatus !== FlowStatus::Paused) {
            $this->runControl->stopActiveForFlow((int) $updated->id, 'flow_paused');
        }

        $this->events->record((int) $updated->tenant_id, 'COMMUNICATION_FLOW_UPDATED', [
            'flow_id' => (int) $updated->id,
            'status' => $updated->status->value,
            'lock_version' => (int) $updated->lock_version,
        ], actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json(['data' => $this->flowPayload($updated)]);
    }

    public function destroy(Request $request, int $flow): JsonResponse
    {
        $this->denyIfFlowsDisabled();
        $model = CommunicationFlow::query()->findOrFail($flow);
        $this->access->assertManageFlows($this->actor($request), $model);
        $tenantId = (int) $model->tenant_id;
        $flowId = (int) $model->id;
        $model->delete();

        $this->events->record($tenantId, 'COMMUNICATION_FLOW_DELETED', [
            'flow_id' => $flowId,
        ], actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json(status: 204);
    }

    public function showDraft(Request $request, int $flow): JsonResponse
    {
        $model = CommunicationFlow::query()->findOrFail($flow);
        $this->access->assertViewFlows($this->actor($request), $model);
        $draft = $model->draft()->firstOrFail();

        return response()->json(['data' => $this->draftPayload($draft)]);
    }

    public function updateDraft(UpdateCommunicationFlowDraftRequest $request, int $flow): JsonResponse
    {
        $this->denyIfFlowsDisabled();
        $model = CommunicationFlow::query()->findOrFail($flow);
        $this->access->assertManageFlows($this->actor($request), $model);
        $data = $request->validated();
        /** @var array{nodes: list<mixed>, edges: list<mixed>} $graph */
        $graph = $data['graph'];
        $digest = $this->canonicalizer->digest($graph);

        $updated = DB::transaction(function () use ($model, $data, $graph, $digest): ?CommunicationFlowDraft {
            $draft = CommunicationFlowDraft::query()
                ->where('flow_id', $model->id)
                ->lockForUpdate()
                ->first();
            if ($draft === null || (int) $draft->lock_version !== (int) $data['lock_version']) {
                return null;
            }
            $draft->fill([
                'graph_encrypted' => $graph,
                'graph_digest' => $digest,
                'lock_version' => (int) $data['lock_version'] + 1,
                'updated_by_membership_id' => $this->currentTenant->realMembership()?->id,
            ]);
            $draft->save();

            return $draft;
        });

        if ($updated === null) {
            return $this->versionConflict('Draft foi alterado por outro usuário.');
        }

        $this->events->record((int) $model->tenant_id, 'COMMUNICATION_FLOW_DRAFT_UPDATED', [
            'flow_id' => (int) $model->id,
            'draft_id' => (int) $updated->id,
            'graph_digest' => $updated->graph_digest,
            'lock_version' => (int) $updated->lock_version,
        ], actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json(['data' => $this->draftPayload($updated)]);
    }

    public function validateGraph(Request $request, int $flow): JsonResponse
    {
        $this->denyIfFlowsDisabled();
        $model = CommunicationFlow::query()->findOrFail($flow);
        $this->access->assertManageFlows($this->actor($request), $model);

        $payload = $request->validate([
            'graph' => ['sometimes', 'array'],
            'graph.nodes' => ['required_with:graph', 'array'],
            'graph.edges' => ['required_with:graph', 'array'],
        ]);

        if (isset($payload['graph'])) {
            /** @var array{nodes: list<mixed>, edges: list<mixed>} $graph */
            $graph = $payload['graph'];
        } else {
            $draft = $model->draft()->firstOrFail();
            /** @var array{nodes: list<mixed>, edges: list<mixed>} $graph */
            $graph = is_array($draft->graph_encrypted) ? $draft->graph_encrypted : self::EMPTY_GRAPH;
        }

        $result = $this->validator->validate($graph, (int) $model->tenant_id);
        if (! $result->valid) {
            return $this->invalidGraphResponse($result->errors, $result->digest);
        }

        return response()->json([
            'data' => [
                'valid' => true,
                'graph_digest' => $result->digest,
            ],
        ]);
    }

    public function dryRun(Request $request, int $flow): JsonResponse
    {
        $this->denyIfFlowsDisabled();
        $model = CommunicationFlow::query()->findOrFail($flow);
        $this->access->assertManageFlows($this->actor($request), $model);

        $payload = $request->validate([
            'graph' => ['sometimes', 'array'],
            'graph.nodes' => ['required_with:graph', 'array'],
            'graph.edges' => ['required_with:graph', 'array'],
            'context' => ['sometimes', 'array'],
            'context.contact_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'context.conversation_status' => ['sometimes', 'nullable', 'string', 'max:64'],
            'context.last_inbound_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'context.question_answers' => ['sometimes', 'array'],
            'context.question_answers.*' => ['string', 'max:500'],
        ]);

        if (isset($payload['graph'])) {
            /** @var array{nodes: list<mixed>, edges: list<mixed>} $graph */
            $graph = $payload['graph'];
        } else {
            $draft = $model->draft()->firstOrFail();
            /** @var array{nodes: list<mixed>, edges: list<mixed>} $graph */
            $graph = is_array($draft->graph_encrypted) ? $draft->graph_encrypted : self::EMPTY_GRAPH;
        }

        /** @var array<string, mixed> $context */
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $result = $this->dryRun->simulate($graph, (int) $model->tenant_id, $context);

        Log::info('communication.flow.dry_run', LogSanitizer::redact([
            'flow_id' => (int) $model->id,
            'tenant_id' => (int) $model->tenant_id,
            'graph_digest' => $result->graphDigest,
            'outcome' => $result->outcome,
            'valid' => $result->valid,
            'steps_count' => count($result->steps),
        ]));

        $this->events->record((int) $model->tenant_id, 'COMMUNICATION_FLOW_DRY_RUN', [
            'flow_id' => (int) $model->id,
            'graph_digest' => $result->graphDigest,
            'outcome' => $result->outcome,
            'valid' => $result->valid,
            'steps_count' => count($result->steps),
        ], actorMembershipId: $this->currentTenant->realMembership()?->id);

        if (! $result->valid) {
            return $this->invalidGraphResponse($result->errors, $result->graphDigest);
        }

        return response()->json(['data' => $result->toArray()]);
    }

    public function previewGraph(Request $request, int $flow): JsonResponse
    {
        $this->denyIfFlowsDisabled();
        $model = CommunicationFlow::query()->findOrFail($flow);
        $this->access->assertManageFlows($this->actor($request), $model);

        $payload = $request->validate([
            'graph' => ['sometimes', 'array'],
            'graph.nodes' => ['required_with:graph', 'array'],
            'graph.edges' => ['required_with:graph', 'array'],
        ]);

        if (isset($payload['graph'])) {
            /** @var array{nodes: list<mixed>, edges: list<mixed>} $graph */
            $graph = $payload['graph'];
        } else {
            $draft = $model->draft()->firstOrFail();
            /** @var array{nodes: list<mixed>, edges: list<mixed>} $graph */
            $graph = is_array($draft->graph_encrypted) ? $draft->graph_encrypted : self::EMPTY_GRAPH;
        }

        $preview = $this->graphPreview->preview($graph);

        Log::info('communication.flow.preview', LogSanitizer::redact([
            'flow_id' => (int) $model->id,
            'tenant_id' => (int) $model->tenant_id,
            'graph_digest' => $preview['graph_digest'],
            'masked_paths_count' => count($preview['masked_paths']),
        ]));

        return response()->json(['data' => $preview]);
    }

    public function publish(Request $request, int $flow): JsonResponse
    {
        $this->denyIfFlowsDisabled();
        $model = CommunicationFlow::query()->findOrFail($flow);
        $this->access->assertManageFlows($this->actor($request), $model);
        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        $published = DB::transaction(function () use ($model, $data): CommunicationFlowVersion|array|null {
            $draft = CommunicationFlowDraft::query()
                ->where('flow_id', $model->id)
                ->lockForUpdate()
                ->first();
            if ($draft === null || (int) $draft->lock_version !== (int) $data['lock_version']) {
                return null;
            }
            /** @var array{nodes: list<mixed>, edges: list<mixed>} $graph */
            $graph = is_array($draft->graph_encrypted) ? $draft->graph_encrypted : self::EMPTY_GRAPH;
            $result = $this->validator->validate($graph, (int) $model->tenant_id);
            if (! $result->valid) {
                return ['errors' => $result->errors, 'digest' => $result->digest];
            }

            $nextVersion = (int) CommunicationFlowVersion::query()
                ->where('flow_id', $model->id)
                ->max('version') + 1;

            return CommunicationFlowVersion::query()->create([
                'tenant_id' => $model->tenant_id,
                'flow_id' => $model->id,
                'version' => $nextVersion,
                'graph_encrypted' => $graph,
                'graph_digest' => $result->digest,
                'published_at' => now(),
                'published_by_membership_id' => $this->currentTenant->realMembership()?->id,
            ]);
        });

        if ($published === null) {
            return $this->versionConflict('Draft foi alterado por outro usuário.');
        }
        if (is_array($published)) {
            return $this->invalidGraphResponse($published['errors'], $published['digest']);
        }

        $enabledBindings = CommunicationFlowInboxBinding::query()
            ->where('flow_id', $model->id)
            ->where('enabled', true)
            ->count();

        $this->events->record((int) $model->tenant_id, 'COMMUNICATION_FLOW_PUBLISHED', [
            'flow_id' => (int) $model->id,
            'version_id' => (int) $published->id,
            'version' => (int) $published->version,
            'graph_digest' => $published->graph_digest,
            'enabled_bindings' => $enabledBindings,
        ], actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json([
            'data' => [
                'version' => $this->versionPayload($published),
                'flow' => $this->flowPayload($model->fresh()),
                'bindings_enabled' => $enabledBindings,
            ],
        ], 201);
    }

    public function cloneFlow(Request $request, int $flow): JsonResponse
    {
        $this->denyIfFlowsDisabled();
        $source = CommunicationFlow::query()->with('draft')->findOrFail($flow);
        $this->access->assertManageFlows($this->actor($request), $source);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'from_version_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $tenantId = (int) $this->currentTenant->tenant()->id;

        $graph = self::EMPTY_GRAPH;
        if (! empty($data['from_version_id'])) {
            $version = CommunicationFlowVersion::query()
                ->where('flow_id', $source->id)
                ->whereKey((int) $data['from_version_id'])
                ->firstOrFail();
            $graph = is_array($version->graph_encrypted) ? $version->graph_encrypted : self::EMPTY_GRAPH;
        } elseif ($source->draft !== null) {
            $graph = is_array($source->draft->graph_encrypted) ? $source->draft->graph_encrypted : self::EMPTY_GRAPH;
        }

        $clone = DB::transaction(function () use ($tenantId, $data, $graph): CommunicationFlow {
            $flow = CommunicationFlow::query()->create([
                'tenant_id' => $tenantId,
                'name' => trim($data['name']),
                'status' => FlowStatus::Paused,
                'lock_version' => 1,
                'created_by_membership_id' => $this->currentTenant->realMembership()?->id,
            ]);
            CommunicationFlowDraft::query()->create([
                'tenant_id' => $tenantId,
                'flow_id' => $flow->id,
                'graph_encrypted' => $graph,
                'graph_digest' => $this->canonicalizer->digest($graph),
                'lock_version' => 1,
                'updated_by_membership_id' => $this->currentTenant->realMembership()?->id,
            ]);

            return $flow;
        });

        $this->events->record($tenantId, 'COMMUNICATION_FLOW_CLONED', [
            'flow_id' => (int) $clone->id,
            'source_flow_id' => (int) $source->id,
            'from_version_id' => $data['from_version_id'] ?? null,
        ], actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json(['data' => $this->flowPayload($clone->load('draft'))], 201);
    }

    public function cloneVersion(Request $request, int $flow, int $version): JsonResponse
    {
        $request->merge(['from_version_id' => $version]);
        if (! $request->filled('name')) {
            $source = CommunicationFlow::query()->findOrFail($flow);
            $request->merge(['name' => $source->name.' (cópia)']);
        }

        return $this->cloneFlow($request, $flow);
    }

    public function indexBindings(Request $request, int $flow): JsonResponse
    {
        $model = CommunicationFlow::query()->findOrFail($flow);
        $this->access->assertViewFlows($this->actor($request), $model);
        $bindings = CommunicationFlowInboxBinding::query()
            ->where('flow_id', $model->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $bindings->map(fn (CommunicationFlowInboxBinding $b): array => $this->bindingPayload($b))->values(),
        ]);
    }

    public function storeBinding(StoreCommunicationFlowBindingRequest $request, int $flow): JsonResponse
    {
        $this->denyIfFlowsDisabled();
        $model = CommunicationFlow::query()->findOrFail($flow);
        $this->access->assertManageFlows($this->actor($request), $model);
        $data = $request->validated();
        $inbox = CommunicationInbox::query()->findOrFail((int) $data['inbox_id']);
        $versionId = isset($data['published_version_id']) ? (int) $data['published_version_id'] : null;
        $enabled = (bool) ($data['enabled'] ?? false);

        if ($versionId !== null) {
            $this->assertVersionOfFlow($model, $versionId);
        }
        if ($enabled) {
            if ($versionId === null) {
                return response()->json([
                    'message' => 'Binding habilitado exige versão publicada.',
                    'code' => 'published_version_required',
                ], 422);
            }
        } else {
            $enabled = false;
        }

        try {
            $binding = CommunicationFlowInboxBinding::query()->create([
                'tenant_id' => $model->tenant_id,
                'flow_id' => $model->id,
                'inbox_id' => $inbox->id,
                'published_version_id' => $versionId,
                'enabled' => $enabled,
                'lock_version' => 1,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                return $this->bindingConflictResponse();
            }
            throw $e;
        }

        $this->events->record((int) $model->tenant_id, 'COMMUNICATION_FLOW_BINDING_CREATED', [
            'flow_id' => (int) $model->id,
            'binding_id' => (int) $binding->id,
            'inbox_id' => (int) $binding->inbox_id,
            'enabled' => (bool) $binding->enabled,
            'published_version_id' => $binding->published_version_id,
        ], inboxId: (int) $binding->inbox_id,
            actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json(['data' => $this->bindingPayload($binding)], 201);
    }

    public function updateBinding(UpdateCommunicationFlowBindingRequest $request, int $binding): JsonResponse
    {
        $this->denyIfFlowsDisabled();
        $model = CommunicationFlowInboxBinding::query()->findOrFail($binding);
        $this->access->assertManageFlows($this->actor($request), $model);
        $data = $request->validated();
        $wasEnabled = (bool) $model->enabled;
        $wantEnabled = array_key_exists('enabled', $data) ? (bool) $data['enabled'] : (bool) $model->enabled;
        $versionId = array_key_exists('published_version_id', $data)
            ? ($data['published_version_id'] !== null ? (int) $data['published_version_id'] : null)
            : ($model->published_version_id !== null ? (int) $model->published_version_id : null);

        if ($versionId !== null) {
            $flow = CommunicationFlow::query()->findOrFail((int) $model->flow_id);
            $this->assertVersionOfFlow($flow, $versionId);
        }
        if ($wantEnabled && $versionId === null) {
            return response()->json([
                'message' => 'Binding habilitado exige versão publicada.',
                'code' => 'published_version_required',
            ], 422);
        }

        try {
            $updated = DB::transaction(function () use ($model, $data, $wantEnabled, $versionId): ?CommunicationFlowInboxBinding {
                $fresh = CommunicationFlowInboxBinding::query()->whereKey($model->id)->lockForUpdate()->first();
                if ($fresh === null || (int) $fresh->lock_version !== (int) $data['lock_version']) {
                    return null;
                }
                $fresh->fill([
                    'published_version_id' => $versionId,
                    'enabled' => $wantEnabled,
                    'lock_version' => (int) $data['lock_version'] + 1,
                ]);
                $fresh->save();

                return $fresh;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                return $this->bindingConflictResponse();
            }
            throw $e;
        }

        if ($updated === null) {
            return $this->versionConflict('Binding foi alterado por outro usuário.');
        }

        if ($wasEnabled && ! (bool) $updated->enabled) {
            $this->runControl->stopActiveForBinding((int) $updated->id, 'binding_disabled');
        }

        $this->events->record((int) $updated->tenant_id, 'COMMUNICATION_FLOW_BINDING_UPDATED', [
            'flow_id' => (int) $updated->flow_id,
            'binding_id' => (int) $updated->id,
            'inbox_id' => (int) $updated->inbox_id,
            'enabled' => (bool) $updated->enabled,
            'published_version_id' => $updated->published_version_id,
            'lock_version' => (int) $updated->lock_version,
        ], inboxId: (int) $updated->inbox_id,
            actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json(['data' => $this->bindingPayload($updated)]);
    }

    public function enableBinding(Request $request, int $binding): JsonResponse
    {
        return $this->setBindingEnabled($request, $binding, true);
    }

    public function disableBinding(Request $request, int $binding): JsonResponse
    {
        return $this->setBindingEnabled($request, $binding, false);
    }

    private function setBindingEnabled(Request $request, int $binding, bool $enabled): JsonResponse
    {
        $this->denyIfFlowsDisabled();
        $model = CommunicationFlowInboxBinding::query()->findOrFail($binding);
        $this->access->assertManageFlows($this->actor($request), $model);
        $wasEnabled = (bool) $model->enabled;
        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'published_version_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $versionId = array_key_exists('published_version_id', $data)
            ? ($data['published_version_id'] !== null ? (int) $data['published_version_id'] : null)
            : ($model->published_version_id !== null ? (int) $model->published_version_id : null);

        if ($enabled && $versionId === null) {
            return response()->json([
                'message' => 'Binding habilitado exige versão publicada.',
                'code' => 'published_version_required',
            ], 422);
        }
        if ($versionId !== null) {
            $flow = CommunicationFlow::query()->findOrFail((int) $model->flow_id);
            $this->assertVersionOfFlow($flow, $versionId);
        }

        try {
            $updated = DB::transaction(function () use ($model, $data, $enabled, $versionId): ?CommunicationFlowInboxBinding {
                $fresh = CommunicationFlowInboxBinding::query()->whereKey($model->id)->lockForUpdate()->first();
                if ($fresh === null || (int) $fresh->lock_version !== (int) $data['lock_version']) {
                    return null;
                }
                $fresh->fill([
                    'published_version_id' => $versionId,
                    'enabled' => $enabled,
                    'lock_version' => (int) $data['lock_version'] + 1,
                ]);
                $fresh->save();

                return $fresh;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                return $this->bindingConflictResponse();
            }
            throw $e;
        }

        if ($updated === null) {
            return $this->versionConflict('Binding foi alterado por outro usuário.');
        }

        if ($wasEnabled && ! (bool) $updated->enabled) {
            $this->runControl->stopActiveForBinding((int) $updated->id, 'binding_disabled');
        }

        $this->events->record((int) $updated->tenant_id, 'COMMUNICATION_FLOW_BINDING_UPDATED', [
            'flow_id' => (int) $updated->flow_id,
            'binding_id' => (int) $updated->id,
            'inbox_id' => (int) $updated->inbox_id,
            'enabled' => (bool) $updated->enabled,
            'published_version_id' => $updated->published_version_id,
            'lock_version' => (int) $updated->lock_version,
        ], inboxId: (int) $updated->inbox_id,
            actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json(['data' => $this->bindingPayload($updated)]);
    }

    public function destroyBinding(Request $request, int $binding): JsonResponse
    {
        $this->denyIfFlowsDisabled();
        $model = CommunicationFlowInboxBinding::query()->findOrFail($binding);
        $this->access->assertManageFlows($this->actor($request), $model);
        $payload = [
            'flow_id' => (int) $model->flow_id,
            'binding_id' => (int) $model->id,
            'inbox_id' => (int) $model->inbox_id,
        ];
        $tenantId = (int) $model->tenant_id;
        $inboxId = (int) $model->inbox_id;
        $model->delete();

        $this->events->record($tenantId, 'COMMUNICATION_FLOW_BINDING_DELETED', $payload,
            inboxId: $inboxId,
            actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json(status: 204);
    }

    private function denyIfFlowsDisabled(): void
    {
        try {
            $this->flowsAvailability->assertEnabled();
        } catch (DomainException) {
            throw new HttpResponseException(response()->json([
                'message' => 'Engine de fluxos desabilitada.',
                'code' => 'communication_flows_disabled',
            ], 403));
        }
    }

    private function assertVersionOfFlow(CommunicationFlow $flow, int $versionId): void
    {
        $exists = CommunicationFlowVersion::query()
            ->where('flow_id', $flow->id)
            ->whereKey($versionId)
            ->exists();
        abort_unless($exists, 422, 'Versão publicada inválida para este fluxo.');
    }

    /** @return array<string, mixed> */
    private function flowPayload(CommunicationFlow $flow, bool $detailed = false): array
    {
        $payload = [
            'id' => (int) $flow->id,
            'name' => $flow->name,
            'status' => $flow->status instanceof FlowStatus ? $flow->status->value : (string) $flow->status,
            'lock_version' => (int) $flow->lock_version,
            'created_at' => optional($flow->created_at)?->toIso8601String(),
            'updated_at' => optional($flow->updated_at)?->toIso8601String(),
        ];
        if ($detailed) {
            $payload['draft'] = $flow->draft ? $this->draftPayload($flow->draft) : null;
            $payload['versions'] = $flow->versions
                ? $flow->versions->map(fn (CommunicationFlowVersion $v): array => $this->versionPayload($v))->values()
                : [];
            $payload['bindings'] = $flow->bindings
                ? $flow->bindings->map(fn (CommunicationFlowInboxBinding $b): array => $this->bindingPayload($b))->values()
                : [];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function draftPayload(CommunicationFlowDraft $draft): array
    {
        return [
            'id' => (int) $draft->id,
            'flow_id' => (int) $draft->flow_id,
            'graph' => is_array($draft->graph_encrypted) ? $draft->graph_encrypted : self::EMPTY_GRAPH,
            'graph_digest' => $draft->graph_digest,
            'lock_version' => (int) $draft->lock_version,
            'updated_at' => optional($draft->updated_at)?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function versionPayload(CommunicationFlowVersion $version): array
    {
        return [
            'id' => (int) $version->id,
            'flow_id' => (int) $version->flow_id,
            'version' => (int) $version->version,
            'graph_digest' => $version->graph_digest,
            'published_at' => optional($version->published_at)?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function bindingPayload(CommunicationFlowInboxBinding $binding): array
    {
        return [
            'id' => (int) $binding->id,
            'flow_id' => (int) $binding->flow_id,
            'inbox_id' => (int) $binding->inbox_id,
            'published_version_id' => $binding->published_version_id !== null ? (int) $binding->published_version_id : null,
            'enabled' => (bool) $binding->enabled,
            'lock_version' => (int) $binding->lock_version,
        ];
    }

    /**
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    private function invalidGraphResponse(array $errors, string $digest): JsonResponse
    {
        return response()->json([
            'message' => 'Grafo de fluxo inválido.',
            'code' => 'invalid_flow_graph',
            'graph_digest' => $digest,
            'errors' => $errors,
        ], 422);
    }

    private function versionConflict(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => 'version_conflict',
        ], 409);
    }

    private function bindingConflictResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Já existe um binding habilitado para esta inbox.',
            'code' => 'enabled_binding_conflict',
        ], 409);
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return $sqlState === '23505' || $driverCode === '19' || str_contains(strtolower($e->getMessage()), 'unique');
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
