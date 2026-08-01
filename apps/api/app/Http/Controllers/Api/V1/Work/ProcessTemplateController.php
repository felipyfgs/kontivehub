<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Http\Controllers\Controller;
use App\Http\Requests\Work\ListProcessGenerationBatchesRequest;
use App\Http\Requests\Work\ListProcessTemplatesRequest;
use App\Http\Requests\Work\ShowProcessTemplateRecurrenceRequest;
use App\Http\Requests\Work\ShowProcessTemplateRequest;
use App\Http\Requests\Work\StoreProcessTemplateRequest;
use App\Http\Requests\Work\UpdateProcessTemplateRecurrenceRequest;
use App\Http\Requests\Work\UpdateProcessTemplateRequest;
use App\Http\Resources\Work\ProcessGenerationBatchSummaryCollection;
use App\Http\Resources\Work\ProcessTemplateCollection;
use App\Http\Resources\Work\ProcessTemplateRecurrenceResource;
use App\Http\Resources\Work\ProcessTemplateResource;
use App\Models\WorkProcessTemplate;
use App\Services\Work\ProcessGenerationBatchQuery;
use App\Services\Work\ProcessTemplateQuery;
use App\Services\Work\ProcessTemplateRecurrenceService;
use App\Services\Work\ProcessTemplateWriter;
use Illuminate\Http\JsonResponse;

class ProcessTemplateController extends Controller
{
    public function index(
        ListProcessTemplatesRequest $request,
        ProcessTemplateQuery $query,
    ): JsonResponse {
        return (new ProcessTemplateCollection(
            $query->paginate($request->filters()),
        ))->response();
    }

    public function show(
        ShowProcessTemplateRequest $request,
        WorkProcessTemplate $template,
    ): JsonResponse {
        return (new ProcessTemplateResource(
            $template->load('tasks'),
        ))->response();
    }

    public function store(
        StoreProcessTemplateRequest $request,
        ProcessTemplateWriter $writer,
    ): JsonResponse {
        return (new ProcessTemplateResource(
            $writer->create($request->payload()),
        ))->response()->setStatusCode(201);
    }

    public function update(
        UpdateProcessTemplateRequest $request,
        WorkProcessTemplate $template,
        ProcessTemplateWriter $writer,
    ): JsonResponse {
        return (new ProcessTemplateResource(
            $writer->update($template, $request->payload()),
        ))->response();
    }

    public function showRecurrence(
        ShowProcessTemplateRecurrenceRequest $request,
        WorkProcessTemplate $template,
    ): JsonResponse {
        return (new ProcessTemplateRecurrenceResource($template))->response();
    }

    public function updateRecurrence(
        UpdateProcessTemplateRecurrenceRequest $request,
        WorkProcessTemplate $template,
        ProcessTemplateRecurrenceService $recurrence,
    ): JsonResponse {
        return (new ProcessTemplateRecurrenceResource(
            $recurrence->update($template, $request->payload()),
        ))->response();
    }

    public function generationBatches(
        ListProcessGenerationBatchesRequest $request,
        WorkProcessTemplate $template,
        ProcessGenerationBatchQuery $query,
    ): JsonResponse {
        return (new ProcessGenerationBatchSummaryCollection(
            $query->paginate($template, $request->filters()),
        ))->response();
    }
}
