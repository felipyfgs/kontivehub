<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Actions\Work\CreateProcessCommentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Work\ArchiveProcessRequest;
use App\Http\Requests\Work\BulkProcessesRequest;
use App\Http\Requests\Work\CommentProcessRequest;
use App\Http\Requests\Work\ListProcessesRequest;
use App\Http\Requests\Work\StoreProcessRequest;
use App\Http\Requests\Work\UpdateProcessRequest;
use App\Http\Requests\Work\ViewProcessRequest;
use App\Http\Resources\Work\ProcessBulkResultResource;
use App\Http\Resources\Work\ProcessCollection;
use App\Http\Resources\Work\ProcessCommentResource;
use App\Http\Resources\Work\ProcessResource;
use App\Http\Resources\Work\TimelineResource;
use App\Models\WorkProcess;
use App\Services\Work\ProcessBulkService;
use App\Services\Work\ProcessQuery;
use App\Services\Work\ProcessService;
use App\Services\Work\ProcessViewBuilder;
use App\Services\Work\TimelineQuery;
use Illuminate\Http\JsonResponse;

class ProcessController extends Controller
{
    public function index(
        ListProcessesRequest $request,
        ProcessQuery $query,
    ): JsonResponse {
        return (new ProcessCollection(
            $query->paginate($request->filters()),
        ))->response();
    }

    public function show(
        ViewProcessRequest $request,
        WorkProcess $process,
        ProcessViewBuilder $views,
    ): JsonResponse {
        return ProcessResource::make(
            $views->detailed($process),
        )->response();
    }

    public function store(
        StoreProcessRequest $request,
        ProcessService $service,
        ProcessViewBuilder $views,
    ): JsonResponse {
        $process = $service->createManual($request->creation());

        return ProcessResource::make(
            $views->fromLoaded($process, detailed: true),
        )->response()->setStatusCode(201);
    }

    public function update(
        UpdateProcessRequest $request,
        WorkProcess $process,
        ProcessService $service,
        ProcessViewBuilder $views,
    ): JsonResponse {
        $process = $service->update($process, $request->updateData());

        return ProcessResource::make(
            $views->fromLoaded($process, detailed: true),
        )->response();
    }

    public function archive(
        ArchiveProcessRequest $request,
        WorkProcess $process,
        ProcessService $service,
        ProcessViewBuilder $views,
    ): JsonResponse {
        $process = $service->archive(
            $process,
            $request->lock()->lockVersion,
        );

        return ProcessResource::make(
            $views->fromLoaded($process),
        )->response();
    }

    public function bulk(
        BulkProcessesRequest $request,
        ProcessBulkService $service,
        ProcessViewBuilder $views,
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
            ProcessBulkResultResource::make($result)->resolve($request),
        );
    }

    public function comment(
        CommentProcessRequest $request,
        WorkProcess $process,
        CreateProcessCommentAction $action,
    ): JsonResponse {
        return ProcessCommentResource::make(
            $action->execute($process, $request->comment()),
        )->response()->setStatusCode(201);
    }

    public function timeline(
        ViewProcessRequest $request,
        WorkProcess $process,
        TimelineQuery $timeline,
    ): JsonResponse {
        return TimelineResource::make(
            $timeline->forProcess($process),
        )->response();
    }
}
