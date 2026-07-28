<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Http\Controllers\Controller;
use App\Http\Requests\Work\ConfirmWorkProcessGenerationRequest;
use App\Http\Requests\Work\PreviewWorkProcessGenerationRequest;
use App\Http\Requests\Work\RetryWorkProcessGenerationRequest;
use App\Http\Requests\Work\ShowWorkProcessGenerationRequest;
use App\Http\Resources\WorkProcessGenerationBatchResource;
use App\Models\WorkProcessGenerationBatch;
use App\Models\WorkProcessTemplate;
use App\Services\Work\WorkProcessGenerationService;
use Illuminate\Http\JsonResponse;

class WorkProcessGenerationController extends Controller
{
    public function preview(
        PreviewWorkProcessGenerationRequest $request,
        WorkProcessTemplate $template,
        WorkProcessGenerationService $service,
    ): JsonResponse {
        return (new WorkProcessGenerationBatchResource(
            $service->preview($template, $request->payload()),
        ))->response()->setStatusCode(201);
    }

    public function confirm(
        ConfirmWorkProcessGenerationRequest $request,
        WorkProcessGenerationBatch $batch,
        WorkProcessGenerationService $service,
    ): JsonResponse {
        return (new WorkProcessGenerationBatchResource(
            $service->confirm($batch, $request->idempotencyKey()),
        ))->response();
    }

    public function retry(
        RetryWorkProcessGenerationRequest $request,
        WorkProcessGenerationBatch $batch,
        WorkProcessGenerationService $service,
    ): JsonResponse {
        return (new WorkProcessGenerationBatchResource(
            $service->retryFailedItems($batch),
        ))->response();
    }

    public function show(
        ShowWorkProcessGenerationRequest $request,
        WorkProcessGenerationBatch $batch,
    ): JsonResponse {
        return (new WorkProcessGenerationBatchResource(
            $batch->load('items'),
        ))->response();
    }
}
