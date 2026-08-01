<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Actions\Work\AssignTaskAction;
use App\Actions\Work\CreateTaskCommentAction;
use App\Actions\Work\ShowTaskAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Work\AssignTaskRequest;
use App\Http\Requests\Work\BulkTasksRequest;
use App\Http\Requests\Work\ClaimTaskRequest;
use App\Http\Requests\Work\CommentTaskRequest;
use App\Http\Requests\Work\DownloadTaskEvidenceRequest;
use App\Http\Requests\Work\JustifyTaskRequest;
use App\Http\Requests\Work\ListTasksRequest;
use App\Http\Requests\Work\RemoveTaskEvidenceRequest;
use App\Http\Requests\Work\ReorderTasksRequest;
use App\Http\Requests\Work\StoreTaskRequest;
use App\Http\Requests\Work\TransitionTaskRequest;
use App\Http\Requests\Work\UpdateTaskStructureRequest;
use App\Http\Requests\Work\UploadTaskEvidenceRequest;
use App\Http\Requests\Work\ViewTaskRequest;
use App\Http\Resources\Work\CommentResource;
use App\Http\Resources\Work\OperationResultResource;
use App\Http\Resources\Work\TaskBulkResultResource;
use App\Http\Resources\Work\TaskDetailResource;
use App\Http\Resources\Work\TaskEvidenceResource;
use App\Http\Resources\Work\TaskQueueCollection;
use App\Http\Resources\Work\TaskResource;
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

class TaskController extends Controller
{
    public function queue(
        ListTasksRequest $request,
        QueueQuery $query,
    ): JsonResponse {
        return (new TaskQueueCollection(
            $query->paginate($request->filters()->toArray()),
        ))->response();
    }

    public function show(
        ViewTaskRequest $request,
        WorkTask $task,
        ShowTaskAction $action,
    ): JsonResponse {
        return TaskDetailResource::make(
            $action->execute($task),
        )->response();
    }

    public function start(
        TransitionTaskRequest $request,
        WorkTask $task,
        TaskTransitionService $service,
    ): JsonResponse {
        $task = $service->start(
            $task,
            $request->transition()->lockVersion,
        );

        return TaskResource::make($task)->response();
    }

    public function block(
        TransitionTaskRequest $request,
        WorkTask $task,
        TaskTransitionService $service,
    ): JsonResponse {
        $data = $request->transition();
        $task = $service->block(
            $task,
            $data->lockVersion,
            (string) $data->reason,
        );

        return TaskResource::make($task)->response();
    }

    public function resume(
        TransitionTaskRequest $request,
        WorkTask $task,
        TaskTransitionService $service,
    ): JsonResponse {
        $task = $service->resume(
            $task,
            $request->transition()->lockVersion,
        );

        return TaskResource::make($task)->response();
    }

    public function complete(
        TransitionTaskRequest $request,
        WorkTask $task,
        TaskTransitionService $service,
    ): JsonResponse {
        $task = $service->complete(
            $task,
            $request->transition()->lockVersion,
        );

        return TaskResource::make($task)->response();
    }

    public function dispense(
        JustifyTaskRequest $request,
        WorkTask $task,
        TaskTransitionService $service,
    ): JsonResponse {
        $data = $request->transition();
        $task = $service->dispense(
            $task,
            $data->lockVersion,
            (string) $data->justification,
        );

        return TaskResource::make($task)->response();
    }

    public function reopen(
        JustifyTaskRequest $request,
        WorkTask $task,
        TaskTransitionService $service,
    ): JsonResponse {
        $data = $request->transition();
        $task = $service->reopen(
            $task,
            $data->lockVersion,
            (string) $data->justification,
        );

        return TaskResource::make($task)->response();
    }

    public function claim(
        ClaimTaskRequest $request,
        WorkTask $task,
        ProcessService $service,
    ): JsonResponse {
        $task = $service->claimTask(
            $task,
            $request->transition()->lockVersion,
        );

        return TaskResource::make($task)->response();
    }

    public function assign(
        AssignTaskRequest $request,
        WorkTask $task,
        AssignTaskAction $action,
    ): JsonResponse {
        return TaskResource::make(
            $action->execute($task, $request->assignment()),
        )->response();
    }

    public function storeOnProcess(
        StoreTaskRequest $request,
        WorkProcess $process,
        TaskStructureService $structure,
    ): JsonResponse {
        $task = $structure->addTask(
            $process,
            $request->structure()->attributes,
        );

        return TaskResource::make($task)
            ->response()
            ->setStatusCode(201);
    }

    public function updateStructure(
        UpdateTaskStructureRequest $request,
        WorkTask $task,
        TaskStructureService $structure,
    ): JsonResponse {
        $data = $request->structure();
        $task = $structure->updateTask(
            $task,
            (int) $data->lockVersion,
            $data->attributes,
        );

        return TaskResource::make($task)->response();
    }

    public function reorder(
        ReorderTasksRequest $request,
        WorkProcess $process,
        TaskStructureService $structure,
    ): JsonResponse {
        $data = $request->reorder();
        $structure->reorder(
            $process,
            $data->order,
            $data->justification,
        );

        return OperationResultResource::make([
            'reordered' => true,
        ])->response();
    }

    public function bulk(
        BulkTasksRequest $request,
        BulkService $service,
    ): JsonResponse {
        $data = $request->bulk();
        $result = $service->apply(
            $data->items,
            $data->changes,
            $request->actor(),
        );

        return response()->json(
            TaskBulkResultResource::make($result)->resolve($request),
        );
    }

    public function comment(
        CommentTaskRequest $request,
        WorkTask $task,
        CreateTaskCommentAction $action,
    ): JsonResponse {
        return CommentResource::make(
            $action->execute($task, $request->comment()),
        )->response()->setStatusCode(201);
    }

    public function uploadEvidence(
        UploadTaskEvidenceRequest $request,
        WorkTask $task,
        EvidenceService $service,
    ): JsonResponse {
        $evidence = $service->upload(
            $task,
            $request->evidenceFile(),
        );

        return TaskEvidenceResource::make($evidence)
            ->response()
            ->setStatusCode(201);
    }

    public function downloadEvidence(
        DownloadTaskEvidenceRequest $request,
        WorkTask $task,
        WorkTaskEvidence $evidence,
        EvidenceService $service,
    ): StreamedResponse {
        return $service->downloadForTask($task, $evidence);
    }

    public function removeEvidence(
        RemoveTaskEvidenceRequest $request,
        WorkTask $task,
        WorkTaskEvidence $evidence,
        EvidenceService $service,
    ): JsonResponse {
        $service->removeForTask(
            $task,
            $evidence,
            $request->removal()->reason,
        );

        return OperationResultResource::make([
            'removed' => true,
        ])->response();
    }
}
