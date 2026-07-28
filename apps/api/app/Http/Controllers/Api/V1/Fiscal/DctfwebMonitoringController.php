<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\Actions\Fiscal\ReadDctfwebEvidenceAction;
use App\DTO\Fiscal\Monitoring\FiscalDownloadData;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\Fiscal\Monitoring\DownloadDctfwebEvidenceRequest;
use App\Http\Requests\Fiscal\Monitoring\ListDctfwebHistoryRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewDctfwebClientRequest;
use App\Http\Requests\Fiscal\Mutations\BatchAutomaticPreferencesRequest;
use App\Http\Requests\Fiscal\Mutations\OptionalPeriodKeyRequest;
use App\Http\Requests\Fiscal\Mutations\UpdateCommunicationPreferencesRequest;
use App\Http\Resources\Fiscal\FiscalMonitoringDataResource;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Dctfweb\DctfwebCommunicationService;
use App\Services\Fiscal\Dctfweb\DctfwebMonitoringQueryService;
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
        private readonly TenantAuthorization $authorization,
    ) {}

    public function history(
        ListDctfwebHistoryRequest $request,
        int $client,
        FindFiscalClientAction $findClient,
    ): JsonResponse|FiscalMonitoringDataResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return response()->json([
                'message' => 'Cliente não encontrado no escritório atual.',
                'code' => 'CLIENT_NOT_FOUND',
            ], 404);
        }
        $filters = $request->filters();

        try {
            $data = $this->queries->history(
                $tenant,
                $model,
                $filters->year,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'HISTORY_ERROR'], 422);
        }

        return new FiscalMonitoringDataResource($data);
    }

    public function downloadEvidence(
        DownloadDctfwebEvidenceRequest $request,
        int $client,
        int $evidence,
        FindFiscalClientAction $findClient,
        ReadDctfwebEvidenceAction $readEvidence,
    ): Response|JsonResponse {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, (int) $request->clientId());
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return $this->downloadResponse(
            $readEvidence->handle(
                $tenant,
                $request->evidenceId(),
                $model,
            ),
        );
    }

    public function downloadEvidenceById(
        DownloadDctfwebEvidenceRequest $request,
        int $evidence,
        ReadDctfwebEvidenceAction $readEvidence,
    ): Response|JsonResponse {
        $tenant = $this->currentTenant->tenant();

        return $this->downloadResponse(
            $readEvidence->handle(
                $tenant,
                $request->evidenceId(),
            ),
        );
    }

    public function updatePreferences(UpdateCommunicationPreferencesRequest $request, int $client): JsonResponse
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

        $data = $request->preferences();

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
                'message' => ($text = $e->getMessage()),
                'code' => 'OPTIMISTIC_LOCK_CONFLICT',
            ], 409);
        } catch (HttpException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], $e->getStatusCode());
        } catch (RuntimeException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], 422);
        }

        return response()->json([
            'data' => $this->communication->summary($tenant, $model),
        ]);
    }

    public function batchPreferences(BatchAutomaticPreferencesRequest $request): JsonResponse
    {
        $this->assertCanManageCommunications();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        try {
            $prefs = $this->communication->batchSetAutomatic(
                $tenant,
                $user,
                $request->clientIds(),
                $request->automaticRequested(),
            );
        } catch (HttpException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], $e->getStatusCode());
        } catch (RuntimeException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], 422);
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

    public function preview(
        ViewDctfwebClientRequest $request,
        int $client,
        FindFiscalClientAction $findClient,
    ): JsonResponse|FiscalMonitoringDataResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return new FiscalMonitoringDataResource(
            $this->communication->preview($tenant, $model),
        );
    }

    public function tracking(
        ViewDctfwebClientRequest $request,
        int $client,
        FindFiscalClientAction $findClient,
    ): JsonResponse|FiscalMonitoringDataResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return new FiscalMonitoringDataResource(
            $this->communication->tracking($tenant, $model),
        );
    }

    public function send(OptionalPeriodKeyRequest $request, int $client): JsonResponse
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
        try {
            $data = $this->communication->requestSend($tenant, $model, $actor, $request->periodKey());
        } catch (HttpException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], $e->getStatusCode());
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

    private function downloadResponse(
        ?FiscalDownloadData $download,
    ): Response|JsonResponse {
        if ($download === null) {
            return response()->json(['message' => 'Artefato não encontrado.'], 404);
        }

        return response($download->bytes, 200, [
            'Content-Type' => $download->contentType,
            'Content-Disposition' => 'attachment; filename="'.$download->filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
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
