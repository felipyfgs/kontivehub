<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\OfficeRole;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Services\Integra\Parcelamento\ParcelamentoMonitorAllService;
use App\Services\Integra\Parcelamento\ParcelamentoQueryService;
use App\Services\Integra\Parcelamento\ParcelamentoServiceCatalog;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TaxInstallmentController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly ParcelamentoQueryService $query,
        private readonly FiscalMonitoringRunService $runs,
        private readonly ParcelamentoMonitorAllService $monitorAll,
    ) {}

    public function modalities(): JsonResponse
    {
        $this->assertCanRead();

        return response()->json(['data' => $this->query->modalities()]);
    }

    public function orders(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $clientId = $request->query('client_id');
        $modality = $request->query('modality');

        $page = $this->query->paginateOrders(
            $office,
            $perPage,
            is_numeric($clientId) ? (int) $clientId : null,
            is_string($modality) ? $modality : null,
        );
        $page->getCollection()->transform(fn ($o) => $o->toPublicArray());

        return response()->json($page);
    }

    public function showOrder(int $order): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $model = $this->query->findOrder($office, $order);
        if ($model === null) {
            return response()->json(['message' => 'Pedido não encontrado.'], 404);
        }

        $parcels = $model->parcels()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->get()
            ->map(fn ($p) => $p->toPublicArray());
        $payments = $model->payments()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->get()
            ->map(fn ($payment) => $payment->toPublicArray());

        return response()->json([
            'data' => array_merge($model->toPublicArray(), [
                'parcels' => $parcels,
                'payments' => $payments,
            ]),
        ]);
    }

    public function parcels(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $page = $this->query->paginateParcels(
            $office,
            $perPage,
            is_numeric($request->query('client_id')) ? (int) $request->query('client_id') : null,
            is_numeric($request->query('order_id')) ? (int) $request->query('order_id') : null,
            is_string($request->query('modality')) ? (string) $request->query('modality') : null,
        );
        $page->getCollection()->transform(fn ($p) => $p->toPublicArray());

        return response()->json($page);
    }

    public function guides(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $clientId = $request->query('client_id');

        $page = $this->query->paginateGuides(
            $office,
            $perPage,
            is_numeric($clientId) ? (int) $clientId : null,
        );
        $page->getCollection()->transform(fn ($g) => $g->toPublicArray());

        return response()->json($page);
    }

    /**
     * Enfileira MONITOR (ou outra operação) por modalidade — tenant-scoped.
     */
    public function enqueue(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'modality' => ['required', 'string', 'max:20'],
            'operation_code' => ['sometimes', 'string', 'max:80'],
            'correlation_id' => ['sometimes', 'string', 'max:64'],
            'context' => ['sometimes', 'array'],
        ]);

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
            ->where('office_id', $office->id)
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
                office: $office,
                client: $client,
                systemCode: ParcelamentoServiceCatalog::SOLUTION,
                serviceCode: $modality,
                operationCode: $operation,
                actorId: $request->user()?->id,
                correlationId: $data['correlation_id'] ?? null,
                dispatch: true,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $run->toPublicArray()], 201);
    }

    /** Enfileira as oito modalidades produtivas para até 25 clientes do escritório ativo. */
    public function monitor(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_ids' => ['required', 'array', 'min:1', 'max:25'],
            'client_ids.*' => ['required', 'integer', 'distinct'],
            'correlation_id' => ['sometimes', 'string', 'max:48'],
        ]);

        $clients = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
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
                office: $office,
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

    private function assertCanRead(): void
    {
        if ($this->currentOffice->role() === null) {
            abort(403, 'Perfil não resolvido.');
        }
    }

    private function assertCanWrite(): void
    {
        $role = $this->currentOffice->role();
        if ($role === null || ! in_array($role, [OfficeRole::Admin, OfficeRole::Operator], true)) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }
}
