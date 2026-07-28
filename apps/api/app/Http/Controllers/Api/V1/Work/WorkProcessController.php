<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Actions\Work\CreateWorkProcessCommentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Work\ArchiveWorkProcessRequest;
use App\Http\Requests\Work\BulkWorkProcessesRequest;
use App\Http\Requests\Work\CommentWorkProcessRequest;
use App\Http\Requests\Work\ListWorkProcessesRequest;
use App\Http\Requests\Work\StoreWorkProcessRequest;
use App\Http\Requests\Work\UpdateWorkProcessRequest;
use App\Http\Requests\Work\ViewWorkProcessRequest;
use App\Http\Resources\WorkProcessBulkResultResource;
use App\Http\Resources\WorkProcessCollection;
use App\Http\Resources\WorkProcessCommentResource;
use App\Http\Resources\WorkProcessResource;
use App\Http\Resources\WorkTimelineResource;
use App\Models\WorkProcess;
use App\Services\Work\WorkProcessBulkService;
use App\Services\Work\WorkProcessQuery;
use App\Services\Work\WorkProcessService;
use App\Services\Work\WorkProcessViewBuilder;
use App\Services\Work\WorkTimelineQuery;
use Illuminate\Http\JsonResponse;

class WorkProcessController extends Controller
{
    public function index(
        ListWorkProcessesRequest $request,
        WorkProcessQuery $query,
    ): JsonResponse {
        return (new WorkProcessCollection(
            $query->paginate($request->filters()),
        ))->response();
    }

    public function show(
        ViewWorkProcessRequest $request,
        WorkProcess $process,
        WorkProcessViewBuilder $views,
    ): JsonResponse {
        return WorkProcessResource::make(
            $views->detailed($process),
        )->response();
    }

    public function store(
        StoreWorkProcessRequest $request,
        WorkProcessService $service,
        WorkProcessViewBuilder $views,
    ): JsonResponse {
        $process = $service->createManual($request->creation());

        return WorkProcessResource::make(
            $views->fromLoaded($process, detailed: true),
        )->response()->setStatusCode(201);
    }

    public function update(
        UpdateWorkProcessRequest $request,
        WorkProcess $process,
        WorkProcessService $service,
        WorkProcessViewBuilder $views,
    ): JsonResponse {
        $process = $service->update($process, $request->updateData());

        return WorkProcessResource::make(
            $views->fromLoaded($process, detailed: true),
        )->response();
    }

    public function archive(
        ArchiveWorkProcessRequest $request,
        WorkProcess $process,
        WorkProcessService $service,
        WorkProcessViewBuilder $views,
    ): JsonResponse {
        $process = $service->archive(
            $process,
            $request->lock()->lockVersion,
        );

        return WorkProcessResource::make(
            $views->fromLoaded($process),
        )->response();
    }

    public function bulk(
        BulkWorkProcessesRequest $request,
        WorkProcessBulkService $service,
        WorkProcessViewBuilder $views,
    ): JsonResponse {
        $data = $request->bulk();
        $result = $service->apply(
            $data->items,
            $data->changes,
            $request->actor(),
        );
        $result['succeeded'] = array_map(
            fn (WorkProcess $process) => $views->fromLoaded($process),
            $result['succeeded'],
        );

        return response()->json(
            WorkProcessBulkResultResource::make($result)->resolve($request),
        );
    }

    public function comment(
        CommentWorkProcessRequest $request,
        WorkProcess $process,
        CreateWorkProcessCommentAction $action,
    ): JsonResponse {
        return WorkProcessCommentResource::make(
            $action->execute($process, $request->comment()),
        )->response()->setStatusCode(201);
    }

    public function timeline(
        ViewWorkProcessRequest $request,
        WorkProcess $process,
        WorkTimelineQuery $timeline,
    ): JsonResponse {
        return WorkTimelineResource::make(
            $timeline->forProcess($process),
        )->response();
    }
}
