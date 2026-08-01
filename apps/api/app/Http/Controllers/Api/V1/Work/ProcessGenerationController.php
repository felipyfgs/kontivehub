<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Http\Controllers\Controller;
use App\Http\Requests\Work\ConfirmProcessGenerationRequest;
use App\Http\Requests\Work\PreviewProcessGenerationRequest;
use App\Http\Requests\Work\RetryProcessGenerationRequest;
use App\Http\Requests\Work\ShowProcessGenerationRequest;
use App\Http\Resources\Work\ProcessGenerationBatchResource;
use App\Models\WorkProcessGenerationBatch;
use App\Models\WorkProcessTemplate;
use App\Services\Work\ProcessGenerationService;
use Illuminate\Http\JsonResponse;

class ProcessGenerationController extends Controller
{
    public function preview(
        PreviewProcessGenerationRequest $request,
        WorkProcessTemplate $template,
        ProcessGenerationService $service,
    ): JsonResponse {
        return (new ProcessGenerationBatchResource(
            $service->preview($template, $request->payload()),
        ))->response()->setStatusCode(201);
    }

    public function confirm(
        ConfirmProcessGenerationRequest $request,
        WorkProcessGenerationBatch $batch,
        ProcessGenerationService $service,
    ): JsonResponse {
        return (new ProcessGenerationBatchResource(
            $service->confirm($batch, $request->idempotencyKey()),
        ))->response();
    }

    public function retry(
        RetryProcessGenerationRequest $request,
        WorkProcessGenerationBatch $batch,
        ProcessGenerationService $service,
    ): JsonResponse {
        return (new ProcessGenerationBatchResource(
            $service->retryFailedItems($batch),
        ))->response();
    }

    public function show(
        ShowProcessGenerationRequest $request,
        WorkProcessGenerationBatch $batch,
    ): JsonResponse {
        return (new ProcessGenerationBatchResource(
            $batch->load('items'),
        ))->response();
    }
}
