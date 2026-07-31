<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Actions\Work\AssignWorkTaskAction;
use App\Actions\Work\CreateWorkTaskCommentAction;
use App\Actions\Work\ShowWorkTaskAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Work\AssignWorkTaskRequest;
use App\Http\Requests\Work\BulkWorkTasksRequest;
use App\Http\Requests\Work\ClaimWorkTaskRequest;
use App\Http\Requests\Work\CommentWorkTaskRequest;
use App\Http\Requests\Work\DownloadWorkTaskEvidenceRequest;
use App\Http\Requests\Work\JustifyWorkTaskRequest;
use App\Http\Requests\Work\ListWorkTasksRequest;
use App\Http\Requests\Work\RemoveWorkTaskEvidenceRequest;
use App\Http\Requests\Work\ReorderWorkTasksRequest;
use App\Http\Requests\Work\StoreWorkTaskRequest;
use App\Http\Requests\Work\TransitionWorkTaskRequest;
use App\Http\Requests\Work\UpdateWorkTaskStructureRequest;
use App\Http\Requests\Work\UploadWorkTaskEvidenceRequest;
use App\Http\Requests\Work\ViewWorkTaskRequest;
use App\Http\Resources\WorkCommentResource;
use App\Http\Resources\WorkOperationResultResource;
use App\Http\Resources\WorkTaskBulkResultResource;
use App\Http\Resources\WorkTaskDetailResource;
use App\Http\Resources\WorkTaskEvidenceResource;
use App\Http\Resources\WorkTaskQueueCollection;
use App\Http\Resources\WorkTaskResource;
use App\Models\WorkProcess;
use App\Models\WorkTask;
use App\Models\WorkTaskEvidence;
use App\Services\Work\BulkService;
use App\Services\Work\EvidenceService;
use App\Services\Work\ProcessService;
use App\Services\Work\QueueQuery;
use App\Services\Work\TaskStructureService;
use App\Services\Work\TaskTransitionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkTaskController extends Controller
{
    public function queue(
        ListWorkTasksRequest $request,
        QueueQuery $query,
    ): JsonResponse {
        return (new WorkTaskQueueCollection(
            $query->paginate($request->filters()->toArray()),
        ))->response();
    }

    public function show(
        ViewWorkTaskRequest $request,
        WorkTask $task,
        ShowWorkTaskAction $action,
    ): JsonResponse {
        return WorkTaskDetailResource::make(
            $action->execute($task),
        )->response();
    }

    public function start(
        TransitionWorkTaskRequest $request,
        WorkTask $task,
        TaskTransitionService $service,
    ): JsonResponse {
        $task = $service->start(
            $task,
            $request->transition()->lockVersion,
        );

        return WorkTaskResource::make($task)->response();
    }

    public function block(
        TransitionWorkTaskRequest $request,
        WorkTask $task,
        TaskTransitionService $service,
    ): JsonResponse {
        $data = $request->transition();
        $task = $service->block(
            $task,
            $data->lockVersion,
            (string) $data->reason,
        );

        return WorkTaskResource::make($task)->response();
    }

    public function resume(
        TransitionWorkTaskRequest $request,
        WorkTask $task,
        TaskTransitionService $service,
    ): JsonResponse {
        $task = $service->resume(
            $task,
            $request->transition()->lockVersion,
        );

        return WorkTaskResource::make($task)->response();
    }

    public function complete(
        TransitionWorkTaskRequest $request,
        WorkTask $task,
        TaskTransitionService $service,
    ): JsonResponse {
        $task = $service->complete(
            $task,
            $request->transition()->lockVersion,
        );

        return WorkTaskResource::make($task)->response();
    }

    public function dispense(
        JustifyWorkTaskRequest $request,
        WorkTask $task,
        TaskTransitionService $service,
    ): JsonResponse {
        $data = $request->transition();
        $task = $service->dispense(
            $task,
            $data->lockVersion,
            (string) $data->justification,
        );

        return WorkTaskResource::make($task)->response();
    }

    public function reopen(
        JustifyWorkTaskRequest $request,
        WorkTask $task,
        TaskTransitionService $service,
    ): JsonResponse {
        $data = $request->transition();
        $task = $service->reopen(
            $task,
            $data->lockVersion,
            (string) $data->justification,
        );

        return WorkTaskResource::make($task)->response();
    }

    public function claim(
        ClaimWorkTaskRequest $request,
        WorkTask $task,
        ProcessService $service,
    ): JsonResponse {
        $task = $service->claimTask(
            $task,
            $request->transition()->lockVersion,
        );

        return WorkTaskResource::make($task)->response();
    }

    public function assign(
        AssignWorkTaskRequest $request,
        WorkTask $task,
        AssignWorkTaskAction $action,
    ): JsonResponse {
        return WorkTaskResource::make(
            $action->execute($task, $request->assignment()),
        )->response();
    }

    public function storeOnProcess(
        StoreWorkTaskRequest $request,
        WorkProcess $process,
        TaskStructureService $structure,
    ): JsonResponse {
        $task = $structure->addTask(
            $process,
            $request->structure()->attributes,
        );

        return WorkTaskResource::make($task)
            ->response()
            ->setStatusCode(201);
    }

    public function updateStructure(
        UpdateWorkTaskStructureRequest $request,
        WorkTask $task,
        TaskStructureService $structure,
    ): JsonResponse {
        $data = $request->structure();
        $task = $structure->updateTask(
            $task,
            (int) $data->lockVersion,
            $data->attributes,
        );

        return WorkTaskResource::make($task)->response();
    }

    public function reorder(
        ReorderWorkTasksRequest $request,
        WorkProcess $process,
        TaskStructureService $structure,
    ): JsonResponse {
        $data = $request->reorder();
        $structure->reorder(
            $process,
            $data->order,
            $data->justification,
        );

        return WorkOperationResultResource::make([
            'reordered' => true,
        ])->response();
    }

    public function bulk(
        BulkWorkTasksRequest $request,
        BulkService $service,
    ): JsonResponse {
        $data = $request->bulk();
        $result = $service->apply(
            $data->items,
            $data->changes,
            $request->actor(),
        );

        return response()->json(
            WorkTaskBulkResultResource::make($result)->resolve($request),
        );
    }

    public function comment(
        CommentWorkTaskRequest $request,
        WorkTask $task,
        CreateWorkTaskCommentAction $action,
    ): JsonResponse {
        return WorkCommentResource::make(
            $action->execute($task, $request->comment()),
        )->response()->setStatusCode(201);
    }

    public function uploadEvidence(
        UploadWorkTaskEvidenceRequest $request,
        WorkTask $task,
        EvidenceService $service,
    ): JsonResponse {
        $evidence = $service->upload(
            $task,
            $request->evidenceFile(),
        );

        return WorkTaskEvidenceResource::make($evidence)
            ->response()
            ->setStatusCode(201);
    }

    public function downloadEvidence(
        DownloadWorkTaskEvidenceRequest $request,
        WorkTask $task,
        WorkTaskEvidence $evidence,
        EvidenceService $service,
    ): StreamedResponse {
        return $service->downloadForTask($task, $evidence);
    }

    public function removeEvidence(
        RemoveWorkTaskEvidenceRequest $request,
        WorkTask $task,
        WorkTaskEvidence $evidence,
        EvidenceService $service,
    ): JsonResponse {
        $service->removeForTask(
            $task,
            $evidence,
            $request->removal()->reason,
        );

        return WorkOperationResultResource::make([
            'removed' => true,
        ])->response();
    }
}
