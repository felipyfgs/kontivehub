<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\ControlFlowRunAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ListFlowRunsRequest;
use App\Http\Requests\Communication\ManageFlowRunRequest;
use App\Http\Requests\Communication\ViewFlowRunRequest;
use App\Http\Resources\Communication\FlowRunCollection;
use App\Http\Resources\Communication\FlowRunResource;
use App\Models\CommunicationFlowRun;
use App\Services\Communication\Flows\FlowRunQuery;
use Illuminate\Http\JsonResponse;

final class FlowRunController extends Controller
{
    public function index(
        ListFlowRunsRequest $request,
        FlowRunQuery $query,
    ): JsonResponse {
        return (new FlowRunCollection(
            $query->paginate($request->filters()),
        ))->response();
    }

    public function show(
        ViewFlowRunRequest $request,
        CommunicationFlowRun $run,
    ): JsonResponse {
        return (new FlowRunResource($run))->response();
    }

    public function pause(
        ManageFlowRunRequest $request,
        CommunicationFlowRun $run,
        ControlFlowRunAction $action,
    ): JsonResponse {
        return $this->response($action->pause($run));
    }

    public function resume(
        ManageFlowRunRequest $request,
        CommunicationFlowRun $run,
        ControlFlowRunAction $action,
    ): JsonResponse {
        return $this->response($action->resume($run));
    }

    public function handoff(
        ManageFlowRunRequest $request,
        CommunicationFlowRun $run,
        ControlFlowRunAction $action,
    ): JsonResponse {
        return $this->response($action->handoff($run));
    }

    public function stop(
        ManageFlowRunRequest $request,
        CommunicationFlowRun $run,
        ControlFlowRunAction $action,
    ): JsonResponse {
        return $this->response($action->stop($run));
    }

    public function restart(
        ManageFlowRunRequest $request,
        CommunicationFlowRun $run,
        ControlFlowRunAction $action,
    ): JsonResponse {
        return $this->response($action->restart($run));
    }

    private function response(CommunicationFlowRun $run): JsonResponse
    {
        return (new FlowRunResource($run))->response();
    }
}
