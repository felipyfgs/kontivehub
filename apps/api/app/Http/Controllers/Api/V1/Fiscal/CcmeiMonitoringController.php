<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOfficeContext;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\SimplesMei\CcmeiCertificateIssuanceService;
use App\Services\Fiscal\SimplesMei\CcmeiMonitoringQueryService;
use App\Services\Fiscal\SimplesMei\CcmeiRegistrationStatusQueryService;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CcmeiMonitoringController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly CcmeiMonitoringQueryService $queries,
        private readonly CcmeiRegistrationStatusQueryService $registrationStatusQueries,
        private readonly CcmeiCertificateIssuanceService $issuance,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function issuedCertificates(Request $request, int $client): JsonResponse
    {
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $model = $this->findClient($this->currentOffice->office()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanRead($request, $model);

        return response()->json(['data' => $this->issuance->history($this->currentOffice->office(), $model)]);
    }

    public function issueCertificate(Request $request, int $client): JsonResponse
    {
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $model = $this->findClient($this->currentOffice->office()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanWrite($request, $model);
        $result = $this->issuance->issue($this->currentOffice->office(), $model, bin2hex(random_bytes(8)));

        return response()->json(['data' => $result], ($result['success'] ?? false) ? 202 : 422);
    }

    public function downloadIssuedCertificate(Request $request, int $client, int $certificate): Response|JsonResponse
    {
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();
        $model = $this->findClient($office->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanRead($request, $model);
        $item = $this->issuance->findForDownload($office, $model, $certificate);
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
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }

        $model = $this->findClient($this->currentOffice->office()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanRead($request, $model);

        try {
            return response()->json(['data' => $this->queries->history($this->currentOffice->office(), $model)]);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'CLIENT_NOT_FOUND'], $e->getStatusCode());
        } catch (RuntimeException) {
            return response()->json(['message' => 'Histórico CCMEI indisponível.', 'code' => 'HISTORY_ERROR'], 422);
        }
    }

    public function consult(Request $request, int $client): JsonResponse
    {
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }

        $request->validate(['confirmed' => ['required', 'accepted']]);
        $model = $this->findClient($this->currentOffice->office()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanWrite($request, $model);

        try {
            $run = $this->queries->enqueueManualConsult(
                $this->currentOffice->office(),
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
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $model = $this->findClient($this->currentOffice->office()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanRead($request, $model);
        try {
            return response()->json(['data' => $this->registrationStatusQueries->history($this->currentOffice->office(), $model)]);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'CLIENT_NOT_FOUND'], $e->getStatusCode());
        } catch (RuntimeException) {
            return response()->json(['message' => 'Histórico cadastral CCMEI indisponível.', 'code' => 'HISTORY_ERROR'], 422);
        }
    }

    public function consultRegistrationStatus(Request $request, int $client): JsonResponse
    {
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $model = $this->findClient($this->currentOffice->office()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanWrite($request, $model);
        try {
            $run = $this->registrationStatusQueries->enqueueManualConsult($this->currentOffice->office(), $model, $request->user()?->id);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'CLIENT_NOT_FOUND'], $e->getStatusCode());
        } catch (RuntimeException) {
            return response()->json(['message' => 'Consulta cadastral CCMEI indisponível.', 'code' => 'CCMEI_STATUS_UNAVAILABLE'], 422);
        }

        return response()->json(['data' => $run], 201);
    }

    private function findClient(int $officeId, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
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

    private function rejectClientOfficeId(Request $request): ?JsonResponse
    {
        $suppliedAtTopLevel = $request->attributes->get(EnsureOfficeContext::CLIENT_OFFICE_ID_SUPPLIED) === true;
        $suppliedNested = $this->containsOfficeIdKey($request->query->all())
            || $this->containsOfficeIdKey($request->request->all())
            || ($request->isJson() && $request->json() !== null && $this->containsOfficeIdKey($request->json()->all()));

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
        $office = $this->currentOffice->office();
        if (! FeatureFlags::isModuleEnabled('simples_mei', $office->id)
            && ! (bool) config('fiscal_monitoring.enabled', false)) {
            abort(403, 'Módulo simples_mei desabilitado.');
        }
    }
}
