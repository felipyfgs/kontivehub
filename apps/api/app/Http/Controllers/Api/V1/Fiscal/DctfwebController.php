<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\OfficeRole;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Fiscal\Dctfweb\DctfwebPeriod;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Dctfweb\DctfwebDeclarationService;
use App\Services\Integra\Dctfweb\DctfwebEventIngestionService;
use App\Services\Integra\Dctfweb\DctfwebEvidenceVersioningService;
use App\Services\Integra\Dctfweb\DctfwebMutationGuard;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class DctfwebController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly DctfwebDeclarationService $declarations,
        private readonly DctfwebEventIngestionService $events,
        private readonly DctfwebEvidenceVersioningService $versions,
        private readonly FiscalMonitoringRunService $runs,
        private readonly DctfwebMutationGuard $mutations,
    ) {}

    public function indexDeclarations(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $clientId = $request->query('client_id');

        $page = $this->declarations->paginate(
            $office,
            $perPage,
            is_numeric($clientId) ? (int) $clientId : null,
        );
        $page->getCollection()->transform(fn ($d) => $d->toPublicArray());

        return response()->json($page);
    }

    public function showDeclaration(int $declaration): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $model = $this->declarations->findForOffice($office, $declaration);
        if ($model === null) {
            return response()->json(['message' => 'Declaração não encontrada.'], 404);
        }

        return response()->json([
            'data' => $model->toPublicArray(),
            'evidence_versions' => $this->versions->history($model),
        ]);
    }

    /**
     * Ingere evento de última atualização e agenda reconciliação dirigida.
     */
    public function ingestEvent(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'period_key' => ['required', 'string', 'max:20'],
            'event_type' => ['sometimes', 'string', 'max:80'],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:160'],
            'payload_digest' => ['sometimes', 'nullable', 'string', 'max:64'],
            'enqueue' => ['sometimes', 'boolean'],
        ]);

        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($data['client_id'])
            ->first();

        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $result = $this->events->ingestAndDirect(
                office: $office,
                client: $client,
                periodKey: $data['period_key'],
                eventType: $data['event_type'] ?? DctfwebCodes::EVENT_ULTIMA_ATUALIZACAO,
                externalId: $data['external_id'] ?? null,
                payloadDigest: $data['payload_digest'] ?? null,
                enqueue: (bool) ($data['enqueue'] ?? true),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
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
    public function enqueueConsult(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'period_key' => ['sometimes', 'nullable', 'string', 'max:20'],
            'operation_code' => ['sometimes', 'string', 'max:80'],
            'correlation_id' => ['sometimes', 'string', 'max:64'],
        ]);

        $operation = strtoupper($data['operation_code'] ?? DctfwebCodes::OP_CONSULTAR_RECIBO);
        if (in_array($operation, DctfwebCodes::mutatingOperations(), true)) {
            return response()->json([
                'message' => 'Use o endpoint de mutação para transmissão.',
                'code' => 'USE_MUTATION_ENDPOINT',
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

        $timezone = (string) ($office->timezone ?: 'America/Sao_Paulo');
        $periodKey = $data['period_key'] ?? DctfwebPeriod::toPeriodKey(
            DctfwebPeriod::expectedPa(null, $timezone),
        );
        $declaration = $this->declarations->findOrCreate($office, $client, $periodKey);

        try {
            $run = $this->runs->enqueueManual(
                office: $office,
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
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $run->toPublicArray()], 201);
    }

    /**
     * Tentativa de transmissão — rejeitada se flags mutantes OFF (9.8).
     */
    public function transmit(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'period_key' => ['required', 'string', 'max:20'],
            'correlation_id' => ['sometimes', 'string', 'max:64'],
            'confirmation' => ['required', 'accepted'],
        ]);

        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($data['client_id'])
            ->first();

        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $gate = $this->mutations->assertMayMutate(
            office: $office,
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

        $declaration = $this->declarations->findOrCreate($office, $client, $data['period_key']);

        try {
            $run = $this->runs->enqueueManual(
                office: $office,
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
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $run->toPublicArray()], 201);
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
