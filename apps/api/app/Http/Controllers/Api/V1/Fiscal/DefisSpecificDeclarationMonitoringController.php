<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\Actions\Fiscal\ReadDefisSpecificDeclarationArtifactAction;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\Fiscal\Monitoring\DownloadDefisArtifactRequest;
use App\Http\Requests\Fiscal\Monitoring\ListDefisSpecificDeclarationHistoryRequest;
use App\Http\Requests\Fiscal\Mutations\ConsultDefisSpecificRequest;
use App\Http\Resources\Fiscal\FiscalMonitoringDataResource;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\SimplesMei\DefisSpecificDeclarationMonitoringQueryService;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DefisSpecificDeclarationMonitoringController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly DefisSpecificDeclarationMonitoringQueryService $queries,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function history(
        ListDefisSpecificDeclarationHistoryRequest $request,
        int $client,
        FindFiscalClientAction $findClient,
    ): JsonResponse|FiscalMonitoringDataResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return $this->notFound();
        }
        $request->ensureCanView($model, 'Sem permissão para monitoramento fiscal.');
        $filters = $request->filters();

        return new FiscalMonitoringDataResource(
            $this->queries->history(
                $tenant,
                $model,
                $filters->referenceId,
            ),
        );
    }

    public function consult(ConsultDefisSpecificRequest $request, int $client): JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $this->assertEnabled();
        $model = $this->findClient($client);
        if ($model === null) {
            return $this->notFound();
        }
        $this->can($request, $model, TenantPermission::FiscalSyncTrigger);

        return response()->json(['data' => $this->queries->enqueueManualConsult($this->currentTenant->tenant(), $model, $request->referenceId(), $request->user()?->id)], 201);
    }

    public function download(
        DownloadDefisArtifactRequest $request,
        int $artifact,
        FindFiscalClientAction $findClient,
        ReadDefisSpecificDeclarationArtifactAction $readArtifact,
    ): Response|JsonResponse {
        $tenant = $this->currentTenant->tenant();
        $item = $this->queries->findArtifact($tenant, $request->artifactId());
        $client = $item !== null
            ? $findClient->handle($tenant, (int) $item->client_id)
            : null;
        if ($item === null || $client === null) {
            return $this->notFound();
        }
        $request->ensureCanView($client);
        $download = $readArtifact->handle($tenant, $item);
        if ($download === null) {
            return $this->notFound();
        }

        return response($download->bytes, 200, [
            'Content-Type' => $download->contentType,
            'Content-Disposition' => 'attachment; filename="'.$download->filename.'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
        ]);
    }

    private function findClient(int $client): ?Client
    {
        return Client::query()->withoutGlobalScopes()->where('tenant_id', $this->currentTenant->tenant()->id)->whereKey($client)->first();
    }

    private function can(Request $request, Client $client, TenantPermission $permission): void
    {
        if (! $request->user() instanceof User || ! $this->authorization->allows($request->user(), $permission, $client)) {
            abort(403, 'Sem permissão para monitoramento fiscal.');
        }
    }

    private function assertEnabled(): void
    {
        $tenant = $this->currentTenant->tenant();
        if (! FeatureFlags::isModuleEnabled('simples_mei', $tenant->id) && ! (bool) config('fiscal_monitoring.enabled', false)) {
            abort(403, 'Módulo simples_mei desabilitado.');
        }
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Artefato ou cliente não encontrado no escritório atual.', 'code' => 'NOT_FOUND'], 404);
    }

    private function rejectClientTenantId(Request $request): ?JsonResponse
    {
        $values = [$request->query->all(), $request->request->all(), $request->isJson() && $request->json() !== null ? $request->json()->all() : []];
        $has = $request->attributes->get(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED) === true;
        foreach ($values as $value) {
            $has = $has || $this->hasTenantId($value);
        }

        return $has ? response()->json(['message' => 'tenant_id não é aceito; o escritório é obtido do contexto autenticado.', 'code' => 'CLIENT_TENANT_ID_REJECTED'], 422) : null;
    }

    /** @param array<array-key,mixed> $values */
    private function hasTenantId(array $values): bool
    {
        foreach ($values as $key => $value) {
            if ((is_string($key) && strtolower($key) === 'tenant_id') || (is_array($value) && $this->hasTenantId($value))) {
                return true;
            }
        }

        return false;
    }
}
