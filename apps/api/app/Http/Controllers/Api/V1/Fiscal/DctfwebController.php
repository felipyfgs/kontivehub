<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\ListFiscalClientRecordsRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewFiscalMonitoringSurfaceRequest;
use App\Http\Requests\Fiscal\Mutations\EnqueueDctfwebConsultRequest;
use App\Http\Requests\Fiscal\Mutations\IngestDctfwebEventRequest;
use App\Http\Requests\Fiscal\Mutations\TransmitDctfwebRequest;
use App\Http\Resources\Fiscal\DctfwebDeclarationDetailResource;
use App\Http\Resources\Fiscal\DctfwebDeclarationPageResource;
use App\Models\Client;
use App\Services\Fiscal\Dctfweb\DctfwebPeriod;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Dctfweb\DctfwebDeclarationService;
use App\Services\Integra\Dctfweb\DctfwebEventIngestionService;
use App\Services\Integra\Dctfweb\DctfwebEvidenceVersioningService;
use App\Services\Integra\Dctfweb\DctfwebMutationGuard;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;

class DctfwebController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly DctfwebDeclarationService $declarations,
        private readonly DctfwebEventIngestionService $events,
        private readonly DctfwebEvidenceVersioningService $versions,
        private readonly FiscalMonitoringRunService $runs,
        private readonly DctfwebMutationGuard $mutations,
    ) {}

    public function indexDeclarations(
        ListFiscalClientRecordsRequest $request,
    ): DctfwebDeclarationPageResource {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        return new DctfwebDeclarationPageResource(
            $this->declarations->paginate(
                $tenant,
                $filters->perPage,
                $filters->clientId,
            ),
        );
    }

    public function showDeclaration(
        ViewFiscalMonitoringSurfaceRequest $request,
        int $declaration,
    ): DctfwebDeclarationDetailResource|JsonResponse {
        $tenant = $this->currentTenant->tenant();
        $model = $this->declarations->findForTenant($tenant, $declaration);
        if ($model === null) {
            return response()->json(['message' => 'Declaração não encontrada.'], 404);
        }

        return new DctfwebDeclarationDetailResource([
            'declaration' => $model,
            'evidence_versions' => $this->versions->history($model),
        ]);
    }

    /**
     * Ingere evento de última atualização e agenda reconciliação dirigida.
     */
    public function ingestEvent(IngestDctfwebEventRequest $request): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        $data = $request->payload(); // IngestDctfwebEventRequest

        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($data['client_id'])
            ->first();

        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $result = $this->events->ingestAndDirect(
                tenant: $tenant,
                client: $client,
                periodKey: $data['period_key'],
                eventType: $data['event_type'] ?? DctfwebCodes::EVENT_ULTIMA_ATUALIZACAO,
                externalId: $data['external_id'] ?? null,
                payloadDigest: $data['payload_digest'] ?? null,
                enqueue: (bool) ($data['enqueue'] ?? true),
            );
        } catch (InvalidArgumentException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], 422);
        }

        return response()->json([
            'data' => [
                'duplicate' => $result['duplicate'],
                'period_key' => $result['period_key'],
                'event' => [
                    'id' => $result['event']->id,
                    'status' => $result['event']->status?->value ?? $result['event']->status,
                    'event_hash' => $result['event']->event_hash,
                ],
                'run' => $result['run']?->toPublicArray(),
            ],
        ], $result['duplicate'] ? 200 : 201);
    }

    /**
     * Enfileira consulta somente-leitura (recibo/relatório/xml/darf/monitor).
     */
    public function enqueueConsult(EnqueueDctfwebConsultRequest $request): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        $data = $request->consultData();

        $operation = strtoupper($data['operation_code'] ?? DctfwebCodes::OP_CONSULTAR_RECIBO);
        if (in_array($operation, DctfwebCodes::mutatingOperations(), true)) {
            return response()->json([
                'message' => 'Use o endpoint de mutação para transmissão.',
                'code' => 'USE_MUTATION_ENDPOINT',
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

        $timezone = (string) ($tenant->timezone ?: 'America/Sao_Paulo');
        $periodKey = $data['period_key'] ?? DctfwebPeriod::toPeriodKey(
            DctfwebPeriod::expectedPa(null, $timezone),
        );
        $declaration = $this->declarations->findOrCreate($tenant, $client, $periodKey);

        try {
            $run = $this->runs->enqueueManual(
                tenant: $tenant,
                client: $client,
                systemCode: DctfwebCodes::SYSTEM_DCTFWEB,
                serviceCode: DctfwebCodes::SERVICE_DCTFWEB,
                operationCode: $operation,
                competence: $declaration->competence,
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

    /**
     * Tentativa de transmissão — rejeitada se flags mutantes OFF (9.8).
     */
    public function transmit(TransmitDctfwebRequest $request): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        $data = $request->transmitData();

        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($data['client_id'])
            ->first();

        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $gate = $this->mutations->assertMayMutate(
            tenant: $tenant,
            client: $client,
            systemCode: DctfwebCodes::SYSTEM_DCTFWEB,
            serviceCode: DctfwebCodes::SERVICE_DCTFWEB,
            operationCode: DctfwebCodes::OP_TRANSMITIR,
            periodKey: $data['period_key'],
            actor: $request->user(),
        );

        if (! $gate['allowed']) {
            return response()->json([
                'message' => $gate['message'],
                'code' => $gate['code'],
            ], 403);
        }

        $declaration = $this->declarations->findOrCreate($tenant, $client, $data['period_key']);

        try {
            $run = $this->runs->enqueueManual(
                tenant: $tenant,
                client: $client,
                systemCode: DctfwebCodes::SYSTEM_DCTFWEB,
                serviceCode: DctfwebCodes::SERVICE_DCTFWEB,
                operationCode: DctfwebCodes::OP_TRANSMITIR,
                competence: $declaration->competence,
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
}
