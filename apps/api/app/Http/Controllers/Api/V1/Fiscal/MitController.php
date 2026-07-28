<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\DTO\Integra\MitListaApuracoesRequest;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\Fiscal\Monitoring\ListFiscalClientRecordsRequest;
use App\Http\Requests\Fiscal\Monitoring\ListMitLocalAssessmentsRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewFiscalMonitoringSurfaceRequest;
use App\Http\Requests\Fiscal\Mutations\EncerrarMitRequest;
use App\Http\Requests\Fiscal\Mutations\EnqueueMitConsultRequest;
use App\Http\Requests\Fiscal\Mutations\EnqueueMitListaApuracoesRequest;
use App\Http\Resources\Fiscal\MitAssessmentPageResource;
use App\Http\Resources\Fiscal\MitAssessmentResource;
use App\Http\Resources\Fiscal\MitLocalAssessmentListResource;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Dctfweb\DctfwebMutationGuard;
use App\Services\Integra\Dctfweb\MitAssessmentService;
use App\Services\Integra\Dctfweb\MitListaApuracoesQueryService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class MitController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly MitAssessmentService $mit,
        private readonly FiscalMonitoringRunService $runs,
        private readonly DctfwebMutationGuard $mutations,
        private readonly MitListaApuracoesQueryService $listaApuracoes,
    ) {}

    public function index(
        ListFiscalClientRecordsRequest $request,
    ): MitAssessmentPageResource {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        return new MitAssessmentPageResource(
            $this->mit->paginate(
                $tenant,
                $filters->perPage,
                $filters->clientId,
            ),
        );
    }

    public function show(
        ViewFiscalMonitoringSurfaceRequest $request,
        int $apuracao,
    ): JsonResponse|MitAssessmentResource {
        $tenant = $this->currentTenant->tenant();
        $model = $this->mit->findForTenant($tenant, $apuracao);
        if ($model === null) {
            return response()->json(['message' => 'Apuração MIT não encontrada.'], 404);
        }

        return new MitAssessmentResource($model);
    }

    public function enqueueConsult(EnqueueMitConsultRequest $request): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        $data = $request->payload();

        $operation = strtoupper($data['operation_code'] ?? DctfwebCodes::OP_MIT_SITUACAO);
        if ($operation === DctfwebCodes::OP_MIT_ENCERRAR) {
            return response()->json([
                'message' => 'Use o endpoint de encerramento para mutação MIT.',
                'code' => 'USE_MUTATION_ENDPOINT',
            ], 422);
        }
        if (! in_array($operation, [DctfwebCodes::OP_MIT_APURACAO, DctfwebCodes::OP_MIT_SITUACAO], true)) {
            return response()->json([
                'message' => 'Operação de consulta MIT desconhecida.',
                'code' => 'MIT_OPERATION_INVALID',
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

        $apuracao = $this->mit->findOrCreate($tenant, $client, $data['period_key']);
        $metadata = is_array($apuracao->metadata) ? $apuracao->metadata : [];
        $listMetadata = is_array($metadata['lista_apuracoes_317'] ?? null)
            ? $metadata['lista_apuracoes_317']
            : [];
        $idApuracao = $data['id_apuracao'] ?? $listMetadata['id_apuracao'] ?? null;
        $protocol = isset($data['protocolo_encerramento'])
            ? trim((string) $data['protocolo_encerramento'])
            : '';

        if ($operation === DctfwebCodes::OP_MIT_APURACAO && ! is_numeric($idApuracao)) {
            return response()->json([
                'message' => 'Consulte LISTAAPURACOES317 ou informe id_apuracao.',
                'code' => 'MIT_ID_APURACAO_REQUIRED',
            ], 422);
        }
        if ($operation === DctfwebCodes::OP_MIT_SITUACAO && $protocol === '') {
            return response()->json([
                'message' => 'Informe protocolo_encerramento retornado pelo encerramento MIT.',
                'code' => 'MIT_PROTOCOL_REQUIRED',
            ], 422);
        }

        try {
            $run = $this->runs->enqueueManual(
                tenant: $tenant,
                client: $client,
                systemCode: DctfwebCodes::SYSTEM_MIT,
                serviceCode: DctfwebCodes::SERVICE_MIT,
                operationCode: $operation,
                competence: $apuracao->competence,
                actorId: $request->user()?->id,
                correlationId: $data['correlation_id'] ?? null,
                dispatch: false,
            );
            $progress = is_array($run->progress) ? $run->progress : [];
            $progress['period_key'] = (string) $data['period_key'];
            if (is_numeric($idApuracao)) {
                $progress['idApuracao'] = (int) $idApuracao;
            }
            if ($protocol !== '') {
                $progress['protocoloEncerramento'] = $protocol;
            }
            $run->forceFill(['progress' => $progress])->save();
            ExecuteFiscalMonitoringRunJob::dispatch($run->id)
                ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
        } catch (RuntimeException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], 422);
        }

        return response()->json(['data' => $run->toPublicArray()], 201);
    }

    /**
     * Agenda exclusivamente a consulta oficial MIT/LISTAAPURACOES317.
     * A página sempre lê a projeção local; nenhum GET dispara o SERPRO.
     */
    public function enqueueListaApuracoes(EnqueueMitListaApuracoesRequest $request): JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $data = $request->payload();

        try {
            $filters = MitListaApuracoesRequest::fromArray(array_filter([
                'anoApuracao' => $data['anoApuracao'] ?? null,
                'mesApuracao' => $data['mesApuracao'] ?? null,
                'situacaoApuracao' => $data['situacaoApuracao'] ?? null,
            ], static fn (?int $value): bool => $value !== null));
        } catch (\InvalidArgumentException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], 422);
        }

        $client = $this->findClient((int) $tenant->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $run = $this->listaApuracoes->enqueue(
                tenant: $tenant,
                client: $client,
                filters: $filters,
                actorId: $request->user()?->id,
                correlationId: $data['correlation_id'] ?? null,
            );
        } catch (RuntimeException|\InvalidArgumentException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], 422);
        }

        return response()->json([
            'data' => $run->toPublicArray(),
            'serpro_call' => 'QUEUED',
        ], 201);
    }

    /** Lista exclusivamente projeções locais produzidas por LISTAAPURACOES317. */
    public function indexListaApuracoes(
        ListMitLocalAssessmentsRequest $request,
        FindFiscalClientAction $findClient,
    ): JsonResponse|MitLocalAssessmentListResource {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        $client = $findClient->handle($tenant, $filters->clientId);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return new MitLocalAssessmentListResource([
            'assessments' => $this->listaApuracoes->localList(
                $tenant,
                $client,
                $filters->year,
            ),
        ]);
    }

    /**
     * Encerramento MIT — rejeitado se flags mutantes OFF (9.8).
     */
    public function encerrar(EncerrarMitRequest $request): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        $data = $request->payload();

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
            systemCode: DctfwebCodes::SYSTEM_MIT,
            serviceCode: DctfwebCodes::SERVICE_MIT,
            operationCode: DctfwebCodes::OP_MIT_ENCERRAR,
            periodKey: $data['period_key'],
            actor: $request->user(),
        );

        if (! $gate['allowed']) {
            return response()->json([
                'message' => $gate['message'],
                'code' => $gate['code'],
            ], 403);
        }

        $apuracao = $this->mit->findOrCreate($tenant, $client, $data['period_key']);

        try {
            $run = $this->runs->enqueueManual(
                tenant: $tenant,
                client: $client,
                systemCode: DctfwebCodes::SYSTEM_MIT,
                serviceCode: DctfwebCodes::SERVICE_MIT,
                operationCode: DctfwebCodes::OP_MIT_ENCERRAR,
                competence: $apuracao->competence,
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

    private function findClient(int $tenantId, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($clientId)
            ->first();
    }

    private function rejectClientTenantId(Request $request): ?JsonResponse
    {
        $suppliedAtTopLevel = $request->attributes->get(
            EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED,
        ) === true;
        $suppliedNested = $this->containsTenantIdKey($request->query->all())
            || $this->containsTenantIdKey($request->request->all())
            || ($request->isJson() && $request->json() !== null
                && $this->containsTenantIdKey($request->json()->all()));

        if (! $suppliedAtTopLevel && ! $suppliedNested) {
            return null;
        }

        return response()->json([
            'message' => 'tenant_id não é aceito; o escritório é obtido do contexto autenticado.',
            'code' => 'CLIENT_TENANT_ID_REJECTED',
        ], 422);
    }

    /** @param array<array-key, mixed> $values */
    private function containsTenantIdKey(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && strtolower($key) === 'tenant_id') {
                return true;
            }
            if (is_array($value) && $this->containsTenantIdKey($value)) {
                return true;
            }
        }

        return false;
    }
}
