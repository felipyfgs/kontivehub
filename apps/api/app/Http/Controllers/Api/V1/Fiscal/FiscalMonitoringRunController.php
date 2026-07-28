<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\Mutations\EnqueueFiscalMonitoringRunAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\ListFiscalMonitoringRunsRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewFiscalMonitoringRequest;
use App\Http\Requests\Fiscal\Mutations\EnqueueFiscalMonitoringRunRequest;
use App\Http\Resources\Fiscal\FiscalMonitoringRunPageResource;
use App\Http\Resources\Fiscal\FiscalMonitoringRunResource;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;

class FiscalMonitoringRunController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly FiscalMonitoringRunService $runs,
        private readonly EnqueueFiscalMonitoringRunAction $enqueue,
    ) {}

    public function index(
        ListFiscalMonitoringRunsRequest $request,
    ): FiscalMonitoringRunPageResource {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        return new FiscalMonitoringRunPageResource(
            $this->runs->paginate(
                $tenant,
                $filters->perPage,
                $filters->clientId,
                $filters->status,
            ),
        );
    }

    public function show(
        ViewFiscalMonitoringRequest $request,
        int $run,
    ): JsonResponse|FiscalMonitoringRunResource {
        $tenant = $this->currentTenant->tenant();
        $model = $this->runs->findForTenant($tenant, $run);
        if ($model === null) {
            return response()->json(['message' => 'Execução não encontrada.'], 404);
        }

        return new FiscalMonitoringRunResource($model);
    }

    public function store(EnqueueFiscalMonitoringRunRequest $request): JsonResponse
    {
        $run = $this->enqueue->handle(
            $request->actor(),
            $request->enqueueData(),
        );

        return (new FiscalMonitoringRunResource($run))
            ->response()
            ->setStatusCode(201);
    }
}
