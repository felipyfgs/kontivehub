<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Models\Client;
use App\Models\PgdasdArtifact;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdCommunicationService;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdMonitoringQueryService;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PgdasdMonitoringController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly PgdasdMonitoringQueryService $queries,
        private readonly PgdasdCommunicationService $communication,
        private readonly FiscalEvidenceStore $evidenceStore,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function history(Request $request, int $client): JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient($tenant->id, $client);
        if ($model === null) {
            // 404 sem revelar existência em outro tenant
            return response()->json([
                'message' => 'Cliente não encontrado no escritório atual.',
                'code' => 'CLIENT_NOT_FOUND',
            ], 404);
        }

        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
        ]);
        $yearInt = isset($validated['year']) ? (int) $validated['year'] : null;

        try {
            $data = $this->queries->history($tenant, $model, $yearInt);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'HISTORY_ERROR'], 422);
        }

        return response()->json(['data' => $data]);
    }

    public function collectDocuments(Request $request, int $client): JsonResponse
    {
        $this->assertCanSync();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $this->assertModuleEnabled();
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient($tenant->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $data = $request->validate([
            'period_key' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'declaration_number' => ['sometimes', 'nullable', 'string', 'max:17'],
            'confirmed' => ['required', 'accepted'],
        ]);

        $declarationNumber = trim((string) ($data['declaration_number'] ?? ''));
        $operation = $declarationNumber !== '' ? 'CONSULTAR_RECIBO' : 'CONSULTAR_ULTIMA_DECLARACAO_RECIBO';
        $params = [
            'period_key' => $data['period_key'],
            'periodoApuracao' => str_replace('-', '', (string) $data['period_key']),
        ];
        if ($declarationNumber !== '') {
            $params['numeroDeclaracao'] = $declarationNumber;
        }

        try {
            $run = $this->queries->enqueueDocumentCollect(
                $tenant,
                $model,
                $operation,
                $params,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $run->toPublicArray(),
            'serpro_call' => 'QUEUED',
        ], 201);
    }

    public function downloadArtifact(Request $request, int $client, int $artifact): Response|JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient($tenant->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $pgArtifact = $this->queries->findArtifact($tenant, $model, $artifact);

        return $this->streamArtifact($tenant->id, $pgArtifact);
    }

    /**
     * Download por id do artefato (contrato SPA: /simples-mei/pgdasd/artifacts/{id}/download).
     */
    public function downloadArtifactById(Request $request, int $artifact): Response|JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $pgArtifact = $this->queries->findArtifactForTenant($tenant, $artifact);

        return $this->streamArtifact((int) $tenant->id, $pgArtifact);
    }

    private function streamArtifact(int $tenantId, ?PgdasdArtifact $pgArtifact): Response|JsonResponse
    {
        if ($pgArtifact === null) {
            return response()->json(['message' => 'Artefato não encontrado.'], 404);
        }

        $pgArtifact->loadMissing('evidenceArtifact');
        if ($pgArtifact->evidenceArtifact === null) {
            return response()->json(['message' => 'Artefato não encontrado.'], 404);
        }

        try {
            $bytes = $this->evidenceStore->readAuthorized(
                $pgArtifact->evidenceArtifact,
                $tenantId,
            );
        } catch (\Throwable) {
            return response()->json(['message' => 'Artefato não encontrado.'], 404);
        }

        $filename = $this->sanitizeDownloadFilename(
            $pgArtifact->filename,
            (string) $pgArtifact->kind,
            (int) $pgArtifact->id,
        );

        return response($bytes, 200, [
            'Content-Type' => $pgArtifact->content_type ?: 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Nome seguro para Content-Disposition (sem path traversal / caracteres de header).
     */
    private function sanitizeDownloadFilename(?string $filename, string $kind, int $id): string
    {
        $fallback = 'pgdasd-'.$this->safeToken($kind, 'doc').'-'.$id.'.pdf';
        if ($filename === null || trim($filename) === '') {
            return $fallback;
        }

        $base = basename(str_replace(["\0", '\\'], ['', '/'], $filename));
        $base = preg_replace('/[^\w.\-]+/u', '_', $base) ?? '';
        $base = trim($base, '._');

        if ($base === '' || $base === '.' || $base === '..') {
            return $fallback;
        }

        return mb_substr($base, 0, 180);
    }

    private function safeToken(string $value, string $default): string
    {
        $token = preg_replace('/[^\w\-]+/u', '_', $value) ?? '';
        $token = trim($token, '_');

        return $token !== '' ? mb_substr($token, 0, 40) : $default;
    }

    public function updatePreferences(Request $request, int $client): JsonResponse
    {
        // Autorização antes da validação: ausência de permissão deve retornar 403.
        $this->assertCanManageCommunications();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient($tenant->id, $client);
        if ($model === null) {
            return response()->json([
                'message' => 'Cliente não encontrado no escritório atual.',
                'code' => 'CLIENT_NOT_FOUND',
            ], 404);
        }

        $data = $request->validate([
            'email_enabled' => ['required', 'boolean'],
            'whatsapp_enabled' => ['required', 'boolean'],
            'automatic_requested' => ['required', 'boolean'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        try {
            $this->communication->updatePreferences(
                $tenant,
                $model,
                $user,
                $data,
            );
        } catch (ConflictHttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'OPTIMISTIC_LOCK_CONFLICT',
            ], 409);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->communication->summary($tenant, $model),
        ]);
    }

    public function batchPreferences(Request $request): JsonResponse
    {
        $this->assertCanManageCommunications();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $data = $request->validate([
            'client_ids' => ['required', 'array', 'min:1', 'max:100'],
            'client_ids.*' => ['integer', 'distinct'],
            'automatic_requested' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        try {
            $prefs = $this->communication->batchSetAutomatic(
                $tenant,
                $user,
                $data['client_ids'],
                (bool) $data['automatic_requested'],
            );
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $summaries = $this->communication->summariesForClients(
            $tenant,
            array_map(static fn ($preference): int => (int) $preference->client_id, $prefs),
        );

        return response()->json([
            'data' => array_values($summaries),
            'updated_count' => count($summaries),
        ]);
    }

    public function preview(Request $request, int $client): JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient($tenant->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json([
            'data' => $this->communication->preview($tenant, $model),
        ]);
    }

    public function tracking(Request $request, int $client): JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient($tenant->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json([
            'data' => $this->communication->tracking($tenant, $model),
        ]);
    }

    public function send(Request $request, int $client): JsonResponse
    {
        $this->assertCanSync();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient($tenant->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }
        /** @var User $actor */
        $actor = $request->user();
        $input = $request->validate(['period_key' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/']]);
        try {
            $data = $this->communication->requestSend($tenant, $model, $actor, $input['period_key'] ?? null);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['data' => $data]);
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

    private function assertCanRead(): void
    {
        $this->assertPermission(TenantPermission::FiscalMonitoringView);
    }

    private function assertCanSync(): void
    {
        $this->assertPermission(TenantPermission::FiscalSyncTrigger, 'Sem permissão de sincronização.');
    }

    private function assertCanManageCommunications(): void
    {
        $this->assertPermission(TenantPermission::ClientsManage, 'Sem permissão para alterar comunicação.');
    }

    private function assertPermission(TenantPermission $permission, string $message = 'Perfil não resolvido.'): void
    {
        $actor = request()->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, $permission)) {
            abort(403, $message);
        }
    }

    private function assertModuleEnabled(): void
    {
        $tenant = $this->currentTenant->tenant();
        if (! FeatureFlags::isModuleEnabled('simples_mei', $tenant->id)
            && ! (bool) config('fiscal_monitoring.enabled', false)) {
            abort(403, 'Módulo simples_mei desabilitado.');
        }
    }
}
