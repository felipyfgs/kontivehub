<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Enums\Communication\FlowRunStatus;
use App\Http\Controllers\Controller;
use App\Models\CommunicationFlowRun;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\Flows\CommunicationFlowRunControlService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommunicationFlowRunController extends Controller
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly CurrentTenant $currentTenant,
        private readonly CommunicationFlowRunControlService $controls,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertViewFlows($this->actor($request));
        $data = $request->validate([
            'flow_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:32'],
            'active_only' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = CommunicationFlowRun::query()
            ->orderByDesc('id');

        if (isset($data['flow_id'])) {
            $query->where('flow_id', (int) $data['flow_id']);
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if ($request->boolean('active_only')) {
            $query->whereIn('status', FlowRunStatus::nonTerminalValues());
        }

        $paginator = $query->paginate(min(100, max(1, (int) ($data['per_page'] ?? 30))));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (CommunicationFlowRun $run): array => $this->serialize($run))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, int $run): JsonResponse
    {
        $this->access->assertViewFlows($this->actor($request));
        $model = CommunicationFlowRun::query()->findOrFail($run);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function pause(Request $request, int $run): JsonResponse
    {
        return $this->mutate($request, $run, fn (CommunicationFlowRun $model) => $this->controls->pause(
            $model,
            $this->currentTenant->realMembership(),
        ));
    }

    public function resume(Request $request, int $run): JsonResponse
    {
        return $this->mutate($request, $run, fn (CommunicationFlowRun $model) => $this->controls->resume(
            $model,
            $this->currentTenant->realMembership(),
        ));
    }

    public function handoff(Request $request, int $run): JsonResponse
    {
        return $this->mutate($request, $run, fn (CommunicationFlowRun $model) => $this->controls->handoff(
            $model,
            $this->currentTenant->realMembership(),
        ));
    }

    public function stop(Request $request, int $run): JsonResponse
    {
        return $this->mutate($request, $run, fn (CommunicationFlowRun $model) => $this->controls->stop(
            $model,
            $this->currentTenant->realMembership(),
        ));
    }

    public function restart(Request $request, int $run): JsonResponse
    {
        return $this->mutate($request, $run, fn (CommunicationFlowRun $model) => $this->controls->restart(
            $model,
            $this->currentTenant->realMembership(),
        ));
    }

    /** @param callable(CommunicationFlowRun): CommunicationFlowRun $action */
    private function mutate(Request $request, int $run, callable $action): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertManageFlows($actor);
        $model = CommunicationFlowRun::query()->findOrFail($run);

        $updated = $action($model);

        return response()->json(['data' => $this->serialize($updated)]);
    }

    /** @return array<string, mixed> */
    private function serialize(CommunicationFlowRun $run): array
    {
        return [
            'id' => (int) $run->id,
            'flow_id' => (int) $run->flow_id,
            'flow_version_id' => (int) $run->flow_version_id,
            'binding_id' => $run->binding_id !== null ? (int) $run->binding_id : null,
            'conversation_id' => $run->conversation_id !== null ? (int) $run->conversation_id : null,
            'status' => $run->status instanceof FlowRunStatus
                ? $run->status->value
                : (string) $run->status,
            'current_node_id' => $run->current_node_id,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'waiting_until' => $run->waiting_until?->toIso8601String(),
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
