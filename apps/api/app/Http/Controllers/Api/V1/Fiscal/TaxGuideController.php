<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Guides\ClientGuidesQueryService;
use App\Services\Fiscal\Guides\Exceptions\GuideException;
use App\Services\Fiscal\Guides\GuideDownloadService;
use App\Services\Fiscal\Guides\GuideHighRiskGate;
use App\Services\Fiscal\Guides\GuideIssuanceService;
use App\Services\Fiscal\Guides\GuidePaymentService;
use App\Services\Fiscal\Guides\GuideQueryService;
use App\Services\Fiscal\Guides\GuideReconciliationService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Central de guias — tenant-scoped; mutações OFF por default.
 */
class TaxGuideController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly GuideQueryService $queries,
        private readonly ClientGuidesQueryService $clientGuides,
        private readonly GuideIssuanceService $issuance,
        private readonly GuideDownloadService $downloads,
        private readonly GuidePaymentService $payments,
        private readonly GuideReconciliationService $reconciliation,
        private readonly GuideHighRiskGate $highRisk,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $tenant = $this->currentTenant->tenant();

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $clientId = $request->query('client_id');
        $paymentStatus = $request->query('payment_status');

        $direction = $request->query('sort_direction', '');
        $direction = is_string($direction) ? strtolower($direction) : '';
        $sort = $request->string('sort')->toString();
        $payment = is_string($paymentStatus) ? $paymentStatus : null;
        $resolvedClientId = is_numeric($clientId) ? (int) $clientId : null;

        $result = $this->clientGuides->paginate(
            $tenant,
            $resolvedClientId,
            $perPage,
            $payment,
            $sort,
            $direction,
        );

        $payload = $result['page']->toArray();
        $payload['payment_counters'] = $result['payment_counters'];

        return response()->json($payload);
    }

    public function show(int $guide): JsonResponse
    {
        $this->assertCanRead();
        $tenant = $this->currentTenant->tenant();

        try {
            $model = $this->queries->find($tenant, $guide);
        } catch (GuideException $e) {
            return $this->guideError($e);
        }

        $data = $model->toPublicArray(withVersions: true);
        $data['payment_confirmations'] = $model->paymentConfirmations
            ->map(fn ($c) => $c->toPublicArray())
            ->all();

        return response()->json(['data' => $data]);
    }

    public function preflight(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $tenant = $this->currentTenant->tenant();
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'operation_key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)+$/'],
            'competence_period_key' => ['sometimes', 'nullable', 'string', 'max:20'],
            'debit_ref' => ['sometimes', 'nullable', 'string', 'max:120'],
            'amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $client = $this->resolveClient($tenant->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $preflight = $this->issuance->preflight(
                tenant: $tenant,
                client: $client,
                operationKey: $data['operation_key'],
                competencePeriodKey: $data['competence_period_key'] ?? null,
                debitRef: $data['debit_ref'] ?? null,
                amountCents: isset($data['amount_cents']) ? (int) $data['amount_cents'] : null,
                user: $request->user(),
            );
        } catch (GuideException $e) {
            return $this->guideError($e);
        }

        return response()->json(['data' => $preflight]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $tenant = $this->currentTenant->tenant();
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'operation_key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)+$/'],
            'competence_period_key' => ['sometimes', 'nullable', 'string', 'max:20'],
            'debit_ref' => ['sometimes', 'nullable', 'string', 'max:120'],
            'amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:160'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'force_reissue' => ['sometimes', 'boolean'],
            'confirmation' => ['required', 'boolean'],
            'confirmation_summary' => ['required', 'array'],
            'confirmation_summary.client_id' => ['sometimes'],
            'confirmation_summary.competence_period_key' => ['sometimes'],
            'confirmation_summary.amount_cents' => ['sometimes'],
            'confirmation_summary.effect' => ['sometimes', 'string', 'max:255'],
            'operation_data' => ['sometimes', 'array'],
            'operation_data.uf' => ['sometimes', 'nullable', 'string', 'size:2'],
            'operation_data.municipio' => ['sometimes', 'nullable', 'string', 'max:100'],
            'operation_data.codigoReceita' => ['sometimes', 'string', 'max:10'],
            'operation_data.codigoReceitaExtensao' => ['sometimes', 'string', 'max:10'],
            'operation_data.numeroReferencia' => ['sometimes', 'nullable', 'string', 'max:30'],
            'operation_data.tipoPA' => ['sometimes', 'nullable', 'string', 'max:20'],
            'operation_data.dataPA' => ['sometimes', 'date'],
            'operation_data.vencimento' => ['sometimes', 'nullable', 'date'],
            'operation_data.cota' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'operation_data.valorImposto' => ['sometimes', 'numeric', 'min:0'],
            'operation_data.valorMulta' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'operation_data.valorJuros' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'operation_data.ganhoCapital' => ['sometimes', 'boolean'],
            'operation_data.dataAlienacao' => ['sometimes', 'nullable', 'date'],
            'operation_data.dataConsolidacao' => ['sometimes', 'date'],
            'operation_data.observacao' => ['sometimes', 'nullable', 'string', 'max:200'],
            'operation_data.cno' => ['sometimes', 'nullable', 'string', 'max:20'],
            'operation_data.cnpjPrestador' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $client = $this->resolveClient($tenant->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $result = $this->issuance->issue(
                tenant: $tenant,
                client: $client,
                operationKey: $data['operation_key'],
                competencePeriodKey: $data['competence_period_key'] ?? null,
                debitRef: $data['debit_ref'] ?? null,
                amountCents: isset($data['amount_cents']) ? (int) $data['amount_cents'] : null,
                dueAtIso: isset($data['due_at']) ? (string) $data['due_at'] : null,
                user: $user,
                explicitConfirmation: (bool) $data['confirmation'],
                confirmationSummary: $data['confirmation_summary'],
                idempotencyKey: $data['idempotency_key'] ?? null,
                correlationId: $data['correlation_id'] ?? null,
                forceReissue: (bool) ($data['force_reissue'] ?? false),
                operationData: $data['operation_data'] ?? [],
            );
        } catch (GuideException $e) {
            return $this->guideError($e);
        }

        $status = $result['version']->emission_status?->value;
        $http = match ($status) {
            'UNKNOWN_RESULT' => 202,
            default => $result['reused'] ? 200 : 201,
        };

        return response()->json([
            'data' => [
                'guide' => $result['guide']->toPublicArray(),
                'version' => $result['version']->toPublicArray(),
                'reused' => $result['reused'],
                'substituted' => $result['substituted'],
                'payment_status' => $result['guide']->payment_status?->value,
            ],
        ], $http);
    }

    public function issueDownloadToken(Request $request, int $guide): JsonResponse
    {
        $this->assertCanRead();
        $tenant = $this->currentTenant->tenant();
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        try {
            $model = $this->queries->find($tenant, $guide);
            $version = $model->currentVersion;
            if ($version === null) {
                return response()->json(['message' => 'Documento indisponível.'], 422);
            }
            $token = $this->downloads->issueToken($version, $user, (int) $tenant->id);
        } catch (GuideException $e) {
            return $this->guideError($e);
        }

        return response()->json([
            'data' => [
                'token' => $token['token'],
                'expires_at' => $token['expires_at'],
                'version_id' => $token['version_id'],
                'download_path' => '/api/v1/fiscal/guides/downloads/'.$token['token'],
            ],
        ]);
    }

    public function download(Request $request, string $token): StreamedResponse|JsonResponse
    {
        $this->assertCanRead();
        $tenant = $this->currentTenant->tenant();

        try {
            $payload = $this->downloads->consumeToken(
                $token,
                (int) $tenant->id,
                $request->user() instanceof User ? $request->user() : null,
            );
        } catch (GuideException $e) {
            return $this->guideError($e);
        }

        return response()->streamDownload(function () use ($payload): void {
            echo $payload['bytes'];
        }, $payload['filename'], [
            'Content-Type' => $payload['content_type'],
            'X-Content-SHA256' => $payload['sha256'],
            'Cache-Control' => 'no-store',
        ]);
    }

    public function confirmPayment(Request $request, int $guide): JsonResponse
    {
        $this->assertCanWrite();
        $tenant = $this->currentTenant->tenant();

        try {
            $model = $this->queries->find($tenant, $guide);
            $result = $this->payments->lookupAndConfirm(
                $tenant,
                $model,
                $request->user() instanceof User ? $request->user() : null,
            );
        } catch (GuideException $e) {
            return $this->guideError($e);
        }

        return response()->json([
            'data' => [
                'guide' => $result['guide']->toPublicArray(),
                'confirmation' => $result['confirmation']?->toPublicArray(),
                'lookup_status' => $result['status'],
            ],
        ]);
    }

    public function reconcile(Request $request, int $guide): JsonResponse
    {
        $this->assertCanWrite();
        $tenant = $this->currentTenant->tenant();

        try {
            $model = $this->queries->find($tenant, $guide);
            $version = $model->currentVersion;
            if ($version === null) {
                return response()->json(['message' => 'Versão não encontrada.'], 404);
            }
            $result = $this->reconciliation->reconcile($tenant, $version);
        } catch (GuideException $e) {
            return $this->guideError($e);
        }

        return response()->json([
            'data' => [
                'guide' => $result['guide']->toPublicArray(),
                'version' => $result['version']->toPublicArray(),
                'outcome' => $result['outcome'],
            ],
        ]);
    }

    private function resolveClient(int $tenantId, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($clientId)
            ->first();
    }

    private function guideError(GuideException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->codeKey,
            'context' => $e->context,
        ], $e->httpStatus);
    }

    private function assertCanRead(): void
    {
        if ($this->currentTenant->role() === null) {
            abort(403, 'Perfil não resolvido.');
        }
    }

    private function assertCanWrite(): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalMutationsExecute)) {
            abort(403, 'Sem permissão para operações de guias.');
        }
    }
}
