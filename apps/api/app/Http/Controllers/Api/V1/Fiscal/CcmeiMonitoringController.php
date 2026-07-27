<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\SimplesMei\CcmeiCertificateIssuanceService;
use App\Services\Fiscal\SimplesMei\CcmeiMonitoringQueryService;
use App\Services\Fiscal\SimplesMei\CcmeiRegistrationStatusQueryService;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CcmeiMonitoringController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly CcmeiMonitoringQueryService $queries,
        private readonly CcmeiRegistrationStatusQueryService $registrationStatusQueries,
        private readonly CcmeiCertificateIssuanceService $issuance,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function issuedCertificates(Request $request, int $client): JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $model = $this->findClient($this->currentTenant->tenant()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanRead($request, $model);

        return response()->json(['data' => $this->issuance->history($this->currentTenant->tenant(), $model)]);
    }

    public function issueCertificate(Request $request, int $client): JsonResponse
    {
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $model = $this->findClient($this->currentTenant->tenant()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanWrite($request, $model);
        $result = $this->issuance->issue($this->currentTenant->tenant(), $model, bin2hex(random_bytes(8)));

        return response()->json(['data' => $result], ($result['success'] ?? false) ? 202 : 422);
    }

    public function downloadIssuedCertificate(Request $request, int $client, int $certificate): Response|JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient($tenant->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanRead($request, $model);
        $item = $this->issuance->findForDownload($tenant, $model, $certificate);
        if ($item === null) {
            return response()->json(['message' => 'Certificado não encontrado.'], 404);
        }
        try {
            $contents = $this->issuance->read($item);
        } catch (\Throwable) {
            return response()->json(['message' => 'Certificado não encontrado.'], 404);
        }

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ccmei-certificado-'.$item->id.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function history(Request $request, int $client): JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }

        $model = $this->findClient($this->currentTenant->tenant()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanRead($request, $model);

        try {
            return response()->json(['data' => $this->queries->history($this->currentTenant->tenant(), $model)]);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'CLIENT_NOT_FOUND'], $e->getStatusCode());
        } catch (RuntimeException) {
            return response()->json(['message' => 'Histórico CCMEI indisponível.', 'code' => 'HISTORY_ERROR'], 422);
        }
    }

    public function consult(Request $request, int $client): JsonResponse
    {
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }

        $request->validate(['confirmed' => ['required', 'accepted']]);
        $model = $this->findClient($this->currentTenant->tenant()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanWrite($request, $model);

        try {
            $run = $this->queries->enqueueManualConsult(
                $this->currentTenant->tenant(),
                $model,
                $request->user()?->id,
            );
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'CLIENT_NOT_FOUND'], $e->getStatusCode());
        } catch (RuntimeException) {
            return response()->json(['message' => 'Consulta CCMEI indisponível.', 'code' => 'CCMEI_UNAVAILABLE'], 422);
        }

        return response()->json(['data' => $run], 201);
    }

    public function registrationStatusHistory(Request $request, int $client): JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $model = $this->findClient($this->currentTenant->tenant()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanRead($request, $model);
        try {
            return response()->json(['data' => $this->registrationStatusQueries->history($this->currentTenant->tenant(), $model)]);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'CLIENT_NOT_FOUND'], $e->getStatusCode());
        } catch (RuntimeException) {
            return response()->json(['message' => 'Histórico cadastral CCMEI indisponível.', 'code' => 'HISTORY_ERROR'], 422);
        }
    }

    public function consultRegistrationStatus(Request $request, int $client): JsonResponse
    {
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $model = $this->findClient($this->currentTenant->tenant()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanWrite($request, $model);
        try {
            $run = $this->registrationStatusQueries->enqueueManualConsult($this->currentTenant->tenant(), $model, $request->user()?->id);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'CLIENT_NOT_FOUND'], $e->getStatusCode());
        } catch (RuntimeException) {
            return response()->json(['message' => 'Consulta cadastral CCMEI indisponível.', 'code' => 'CCMEI_STATUS_UNAVAILABLE'], 422);
        }

        return response()->json(['data' => $run], 201);
    }

    private function findClient(int $tenantId, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($clientId)
            ->first();
    }

    private function clientNotFound(): JsonResponse
    {
        return response()->json([
            'message' => 'Cliente não encontrado no escritório atual.',
            'code' => 'CLIENT_NOT_FOUND',
        ], 404);
    }

    private function rejectClientTenantId(Request $request): ?JsonResponse
    {
        $suppliedAtTopLevel = $request->attributes->get(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED) === true;
        $suppliedNested = $this->containsTenantIdKey($request->query->all())
            || $this->containsTenantIdKey($request->request->all())
            || ($request->isJson() && $request->json() !== null && $this->containsTenantIdKey($request->json()->all()));

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

    private function assertCanRead(Request $request, Client $client): void
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, TenantPermission::FiscalMonitoringView, $client)) {
            abort(403, 'Sem permissão para consultar o monitoramento fiscal.');
        }
    }

    private function assertCanWrite(Request $request, Client $client): void
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, TenantPermission::FiscalSyncTrigger, $client)) {
            abort(403, 'Sem permissão de sincronização.');
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
