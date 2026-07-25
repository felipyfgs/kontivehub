<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\DTO\Integra\MitListaApuracoesRequest;
use App\Enums\OfficeRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOfficeContext;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Dctfweb\DctfwebMutationGuard;
use App\Services\Integra\Dctfweb\MitApuracaoService;
use App\Services\Integra\Dctfweb\MitListaApuracoesQueryService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class MitController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly MitApuracaoService $mit,
        private readonly FiscalMonitoringRunService $runs,
        private readonly DctfwebMutationGuard $mutations,
        private readonly MitListaApuracoesQueryService $listaApuracoes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $clientId = $request->query('client_id');

        $page = $this->mit->paginate(
            $office,
            $perPage,
            is_numeric($clientId) ? (int) $clientId : null,
        );
        $page->getCollection()->transform(fn ($m) => $m->toPublicArray());

        return response()->json($page);
    }

    public function show(int $apuracao): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $model = $this->mit->findForOffice($office, $apuracao);
        if ($model === null) {
            return response()->json(['message' => 'Apuração MIT não encontrada.'], 404);
        }

        return response()->json(['data' => $model->toPublicArray()]);
    }

    public function enqueueConsult(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'period_key' => ['required', 'string', 'regex:/^(20\\d{2}|2100)-(0[1-9]|1[0-2])$/'],
            'operation_code' => ['sometimes', 'string', 'max:80'],
            'id_apuracao' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'protocolo_encerramento' => ['sometimes', 'nullable', 'string', 'max:512'],
            'correlation_id' => ['sometimes', 'string', 'max:64'],
        ]);

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
            ->where('office_id', $office->id)
            ->whereKey($data['client_id'])
            ->first();

        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $apuracao = $this->mit->findOrCreate($office, $client, $data['period_key']);
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
                office: $office,
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
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $run->toPublicArray()], 201);
    }

    /**
     * Agenda exclusivamente a consulta oficial MIT/LISTAAPURACOES317.
     * A página sempre lê a projeção local; nenhum GET dispara o SERPRO.
     */
    public function enqueueListaApuracoes(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'anoApuracao' => ['sometimes', 'nullable', 'integer', 'between:2000,2100'],
            'mesApuracao' => ['sometimes', 'nullable', 'integer', 'between:1,12'],
            'situacaoApuracao' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        try {
            $filters = MitListaApuracoesRequest::fromArray(array_filter([
                'anoApuracao' => $data['anoApuracao'] ?? null,
                'mesApuracao' => $data['mesApuracao'] ?? null,
                'situacaoApuracao' => $data['situacaoApuracao'] ?? null,
            ], static fn (?int $value): bool => $value !== null));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $client = $this->findClient((int) $office->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $run = $this->listaApuracoes->enqueue(
                office: $office,
                client: $client,
                filters: $filters,
                actorId: $request->user()?->id,
                correlationId: $data['correlation_id'] ?? null,
            );
        } catch (RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $run->toPublicArray(),
            'serpro_call' => 'QUEUED',
        ], 201);
    }

    /** Lista exclusivamente projeções locais produzidas por LISTAAPURACOES317. */
    public function indexListaApuracoes(Request $request): JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'year' => ['sometimes', 'nullable', 'integer', 'between:2000,2100'],
        ]);

        $client = $this->findClient((int) $office->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json([
            'data' => $this->listaApuracoes->localList(
                $office,
                $client,
                isset($data['year']) ? (int) $data['year'] : null,
            ),
            'provenance' => [
                'source' => 'LOCAL_PROJECTION',
                'serpro_called' => false,
            ],
        ]);
    }

    /**
     * Encerramento MIT — rejeitado se flags mutantes OFF (9.8).
     */
    public function encerrar(Request $request): JsonResponse
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

        $apuracao = $this->mit->findOrCreate($office, $client, $data['period_key']);

        try {
            $run = $this->runs->enqueueManual(
                office: $office,
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

    private function findClient(int $officeId, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereKey($clientId)
            ->first();
    }

    private function rejectClientOfficeId(Request $request): ?JsonResponse
    {
        $suppliedAtTopLevel = $request->attributes->get(
            EnsureOfficeContext::CLIENT_OFFICE_ID_SUPPLIED,
        ) === true;
        $suppliedNested = $this->containsOfficeIdKey($request->query->all())
            || $this->containsOfficeIdKey($request->request->all())
            || ($request->isJson() && $request->json() !== null
                && $this->containsOfficeIdKey($request->json()->all()));

        if (! $suppliedAtTopLevel && ! $suppliedNested) {
            return null;
        }

        return response()->json([
            'message' => 'office_id não é aceito; o escritório é obtido do contexto autenticado.',
            'code' => 'CLIENT_OFFICE_ID_REJECTED',
        ], 422);
    }

    /** @param array<array-key, mixed> $values */
    private function containsOfficeIdKey(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && strtolower($key) === 'office_id') {
                return true;
            }
            if (is_array($value) && $this->containsOfficeIdKey($value)) {
                return true;
            }
        }

        return false;
    }

    private function assertCanWrite(): void
    {
        $role = $this->currentOffice->role();
        if ($role === null || ! in_array($role, [OfficeRole::Admin, OfficeRole::Operator], true)) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }
}
