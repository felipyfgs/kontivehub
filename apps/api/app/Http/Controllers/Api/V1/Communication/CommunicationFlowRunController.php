<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\ControlCommunicationFlowRunAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ListCommunicationFlowRunsRequest;
use App\Http\Requests\Communication\ManageCommunicationFlowRunRequest;
use App\Http\Requests\Communication\ViewCommunicationFlowRunRequest;
use App\Http\Resources\Communication\CommunicationFlowRunCollection;
use App\Http\Resources\Communication\CommunicationFlowRunResource;
use App\Models\CommunicationFlowRun;
use App\Services\Communication\Flows\CommunicationFlowRunQuery;
use Illuminate\Http\JsonResponse;

final class CommunicationFlowRunController extends Controller
{
    public function index(
        ListCommunicationFlowRunsRequest $request,
        CommunicationFlowRunQuery $query,
    ): JsonResponse {
        return (new CommunicationFlowRunCollection(
            $query->paginate($request->filters()),
        ))->response();
    }

    public function show(
        ViewCommunicationFlowRunRequest $request,
        CommunicationFlowRun $run,
    ): JsonResponse {
        return (new CommunicationFlowRunResource($run))->response();
    }

    public function pause(
        ManageCommunicationFlowRunRequest $request,
        CommunicationFlowRun $run,
        ControlCommunicationFlowRunAction $action,
    ): JsonResponse {
        return $this->response($action->pause($run));
    }

    public function resume(
        ManageCommunicationFlowRunRequest $request,
        CommunicationFlowRun $run,
        ControlCommunicationFlowRunAction $action,
    ): JsonResponse {
        return $this->response($action->resume($run));
    }

    public function handoff(
        ManageCommunicationFlowRunRequest $request,
        CommunicationFlowRun $run,
        ControlCommunicationFlowRunAction $action,
    ): JsonResponse {
        return $this->response($action->handoff($run));
    }

    public function stop(
        ManageCommunicationFlowRunRequest $request,
        CommunicationFlowRun $run,
        ControlCommunicationFlowRunAction $action,
    ): JsonResponse {
        return $this->response($action->stop($run));
    }

    public function restart(
        ManageCommunicationFlowRunRequest $request,
        CommunicationFlowRun $run,
        ControlCommunicationFlowRunAction $action,
    ): JsonResponse {
        return $this->response($action->restart($run));
    }

    private function response(CommunicationFlowRun $run): JsonResponse
    {
        return (new CommunicationFlowRunResource($run))->response();
    }
}
