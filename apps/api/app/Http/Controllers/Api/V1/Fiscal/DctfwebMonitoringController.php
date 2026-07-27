<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Models\Client;
use App\Models\DctfwebEvidenceVersion;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Dctfweb\DctfwebCommunicationService;
use App\Services\Fiscal\Dctfweb\DctfwebMonitoringQueryService;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * APIs locais DCTFWeb: histórico, download, comunicação TEMPLATE_ONLY.
 * Nunca dispara SERPRO implicitamente ao abrir UI.
 */
class DctfwebMonitoringController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly DctfwebMonitoringQueryService $queries,
        private readonly DctfwebCommunicationService $communication,
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

    public function downloadEvidence(Request $request, int $client, int $evidence): Response|JsonResponse
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

        $version = $this->queries->findEvidenceVersion($tenant, $model, $evidence);

        return $this->streamEvidence($tenant->id, $version);
    }

    public function downloadEvidenceById(Request $request, int $evidence): Response|JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $version = $this->queries->findEvidenceVersionForTenant($tenant, $evidence);

        return $this->streamEvidence((int) $tenant->id, $version);
    }

    private function streamEvidence(int $tenantId, ?DctfwebEvidenceVersion $version): Response|JsonResponse
    {
        if ($version === null) {
            return response()->json(['message' => 'Artefato não encontrado.'], 404);
        }

        $version->loadMissing('artifact');
        if ($version->artifact === null) {
            return response()->json(['message' => 'Artefato não encontrado.'], 404);
        }

        try {
            $bytes = $this->evidenceStore->readAuthorized(
                $version->artifact,
                $tenantId,
            );
        } catch (\Throwable) {
            return response()->json(['message' => 'Artefato não encontrado.'], 404);
        }

        $document = $this->queries->documentMetadata($version);

        return response($bytes, 200, [
            'Content-Type' => $document['content_type'],
            'Content-Disposition' => 'attachment; filename="'.$document['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function updatePreferences(Request $request, int $client): JsonResponse
    {
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
}
