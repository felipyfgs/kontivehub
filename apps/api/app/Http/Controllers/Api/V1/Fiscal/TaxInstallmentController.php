<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\ListTaxInstallmentGuidesRequest;
use App\Http\Requests\Fiscal\Monitoring\ListTaxInstallmentOrdersRequest;
use App\Http\Requests\Fiscal\Monitoring\ListTaxInstallmentParcelsRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewFiscalMonitoringRequest;
use App\Http\Requests\Fiscal\Mutations\EnqueueTaxInstallmentRequest;
use App\Http\Requests\Fiscal\Mutations\MonitorTaxInstallmentsRequest;
use App\Http\Resources\Fiscal\TaxInstallmentGuidePageResource;
use App\Http\Resources\Fiscal\TaxInstallmentModalityResource;
use App\Http\Resources\Fiscal\TaxInstallmentOrderDetailResource;
use App\Http\Resources\Fiscal\TaxInstallmentOrderPageResource;
use App\Http\Resources\Fiscal\TaxInstallmentParcelPageResource;
use App\Models\Client;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Services\Integra\Parcelamento\ParcelamentoMonitorAllService;
use App\Services\Integra\Parcelamento\ParcelamentoQueryService;
use App\Services\Integra\Parcelamento\ParcelamentoServiceCatalog;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class TaxInstallmentController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly ParcelamentoQueryService $query,
        private readonly FiscalMonitoringRunService $runs,
        private readonly ParcelamentoMonitorAllService $monitorAll,
    ) {}

    public function modalities(
        ViewFiscalMonitoringRequest $request,
    ): AnonymousResourceCollection {
        return TaxInstallmentModalityResource::collection(
            $this->query->modalities(),
        );
    }

    public function orders(
        ListTaxInstallmentOrdersRequest $request,
    ): TaxInstallmentOrderPageResource {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        return new TaxInstallmentOrderPageResource(
            $this->query->paginateOrders(
                $tenant,
                $filters->perPage,
                $filters->clientId,
                $filters->modality,
            ),
        );
    }

    public function showOrder(
        ViewFiscalMonitoringRequest $request,
        int $order,
    ): JsonResponse|TaxInstallmentOrderDetailResource {
        $tenant = $this->currentTenant->tenant();
        $model = $this->query->findOrder($tenant, $order);
        if ($model === null) {
            return response()->json(['message' => 'Pedido não encontrado.'], 404);
        }

        return new TaxInstallmentOrderDetailResource($model);
    }

    public function parcels(
        ListTaxInstallmentParcelsRequest $request,
    ): TaxInstallmentParcelPageResource {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        return new TaxInstallmentParcelPageResource(
            $this->query->paginateParcels(
                $tenant,
                $filters->perPage,
                $filters->clientId,
                $filters->orderId,
                $filters->modality,
            ),
        );
    }

    public function guides(
        ListTaxInstallmentGuidesRequest $request,
    ): TaxInstallmentGuidePageResource {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        return new TaxInstallmentGuidePageResource(
            $this->query->paginateGuides(
                $tenant,
                $filters->perPage,
                $filters->clientId,
            ),
        );
    }

    /**
     * Enfileira MONITOR (ou outra operação) por modalidade — tenant-scoped.
     */
    public function enqueue(EnqueueTaxInstallmentRequest $request): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        $data = $request->enqueueData();

        $modality = strtoupper($data['modality']);
        if (! ParcelamentoServiceCatalog::isKnownModality($modality)) {
            return response()->json(['message' => 'Modalidade de parcelamento inválida.'], 422);
        }
        if (! ParcelamentoServiceCatalog::isExecutableModality($modality)) {
            return response()->json([
                'message' => 'Modalidade inventariada pela SERPRO, mas ainda não disponível para execução.',
                'code' => 'MODALITY_NOT_EXECUTABLE',
            ], 422);
        }

        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($data['client_id'])
            ->first();

        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $operation = strtoupper($data['operation_code'] ?? 'MONITOR');

        // Mutantes: recusa imediata sem enfileirar se flags off (defesa em profundidade)
        if (ParcelamentoServiceCatalog::isMutatingOperation($operation)
            && ! (bool) config('fiscal_monitoring.mutating_enabled', false)) {
            return response()->json([
                'message' => 'Operação mutante de parcelamento não habilitada no piloto.',
                'code' => 'MUTATING_DISABLED',
            ], 403);
        }

        try {
            $run = $this->runs->enqueueManual(
                tenant: $tenant,
                client: $client,
                systemCode: ParcelamentoServiceCatalog::SOLUTION,
                serviceCode: $modality,
                operationCode: $operation,
                actorId: $request->user()?->id,
                correlationId: $data['correlation_id'] ?? null,
                dispatch: true,
            );
        } catch (RuntimeException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], 422);
        }

        return response()->json(['data' => $run->toPublicArray()], 201);
    }

    /** Enfileira as oito modalidades produtivas para até 25 clientes do escritório ativo. */
    public function monitor(MonitorTaxInstallmentsRequest $request): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        $data = $request->monitorData();

        $clients = Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $data['client_ids'])
            ->get()
            ->keyBy('id');

        if ($clients->count() !== count($data['client_ids'])) {
            return response()->json([
                'message' => 'Um ou mais clientes não pertencem ao escritório ativo.',
                'code' => 'CLIENT_SCOPE_INVALID',
            ], 422);
        }

        $results = [];
        $accepted = 0;
        $failed = 0;
        foreach ($data['client_ids'] as $clientId) {
            $result = $this->monitorAll->enqueueClient(
                tenant: $tenant,
                client: $clients->get($clientId),
                actorId: $request->user()?->id,
                correlationId: isset($data['correlation_id'])
                    ? $data['correlation_id'].':'.$clientId
                    : null,
                dispatch: true,
            );
            $accepted += $result['accepted'];
            $failed += $result['failed'];
            $results[] = [
                'client_id' => $clientId,
                ...$result,
            ];
        }

        return response()->json([
            'data' => [
                'clients' => count($results),
                'requested_modalities_per_client' => count(ParcelamentoServiceCatalog::supportedModalities()),
                'accepted' => $accepted,
                'failed' => $failed,
                'results' => $results,
            ],
        ], 202);
    }
}
