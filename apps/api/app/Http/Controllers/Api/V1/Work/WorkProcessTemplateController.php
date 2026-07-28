<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Http\Controllers\Controller;
use App\Http\Requests\Work\ListWorkProcessGenerationBatchesRequest;
use App\Http\Requests\Work\ListWorkProcessTemplatesRequest;
use App\Http\Requests\Work\ShowWorkProcessTemplateRecurrenceRequest;
use App\Http\Requests\Work\ShowWorkProcessTemplateRequest;
use App\Http\Requests\Work\StoreWorkProcessTemplateRequest;
use App\Http\Requests\Work\UpdateWorkProcessTemplateRecurrenceRequest;
use App\Http\Requests\Work\UpdateWorkProcessTemplateRequest;
use App\Http\Resources\WorkProcessGenerationBatchSummaryCollection;
use App\Http\Resources\WorkProcessTemplateCollection;
use App\Http\Resources\WorkProcessTemplateRecurrenceResource;
use App\Http\Resources\WorkProcessTemplateResource;
use App\Models\WorkProcessTemplate;
use App\Services\Work\WorkProcessGenerationBatchQuery;
use App\Services\Work\WorkProcessTemplateQuery;
use App\Services\Work\WorkProcessTemplateRecurrenceService;
use App\Services\Work\WorkProcessTemplateWriter;
use Illuminate\Http\JsonResponse;

class WorkProcessTemplateController extends Controller
{
    public function index(
        ListWorkProcessTemplatesRequest $request,
        WorkProcessTemplateQuery $query,
    ): JsonResponse {
        return (new WorkProcessTemplateCollection(
            $query->paginate($request->filters()),
        ))->response();
    }

    public function show(
        ShowWorkProcessTemplateRequest $request,
        WorkProcessTemplate $template,
    ): JsonResponse {
        return (new WorkProcessTemplateResource(
            $template->load('tasks'),
        ))->response();
    }

    public function store(
        StoreWorkProcessTemplateRequest $request,
        WorkProcessTemplateWriter $writer,
    ): JsonResponse {
        return (new WorkProcessTemplateResource(
            $writer->create($request->payload()),
        ))->response()->setStatusCode(201);
    }

    public function update(
        UpdateWorkProcessTemplateRequest $request,
        WorkProcessTemplate $template,
        WorkProcessTemplateWriter $writer,
    ): JsonResponse {
        return (new WorkProcessTemplateResource(
            $writer->update($template, $request->payload()),
        ))->response();
    }

    public function showRecurrence(
        ShowWorkProcessTemplateRecurrenceRequest $request,
        WorkProcessTemplate $template,
    ): JsonResponse {
        return (new WorkProcessTemplateRecurrenceResource($template))->response();
    }

    public function updateRecurrence(
        UpdateWorkProcessTemplateRecurrenceRequest $request,
        WorkProcessTemplate $template,
        WorkProcessTemplateRecurrenceService $recurrence,
    ): JsonResponse {
        return (new WorkProcessTemplateRecurrenceResource(
            $recurrence->update($template, $request->payload()),
        ))->response();
    }

    public function generationBatches(
        ListWorkProcessGenerationBatchesRequest $request,
        WorkProcessTemplate $template,
        WorkProcessGenerationBatchQuery $query,
    ): JsonResponse {
        return (new WorkProcessGenerationBatchSummaryCollection(
            $query->paginate($template, $request->filters()),
        ))->response();
    }
}
