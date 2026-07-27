<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Models\Client;
use App\Models\PagtowebArrecadacaoReceipt;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Guides\PagtowebArrecadacaoReceiptProjector;
use App\Services\Fiscal\Guides\PagtowebArrecadacaoReceiptQueryService;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use RuntimeException;

final class PagtowebArrecadacaoReceiptController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $tenant,
        private readonly PagtowebArrecadacaoReceiptQueryService $receipts,
        private readonly PagtowebArrecadacaoReceiptProjector $projector,
        private readonly TenantAuthorization $auth,
    ) {}

    public function history(Request $request, int $client): JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $model = $this->client($client);
        if ($model === null) {
            return $this->notFound();
        }
        $this->allows($request, $model, TenantPermission::FiscalMonitoringView);

        return response()->json(['data' => $this->receipts->history($this->tenant->tenant(), $model)]);
    }

    public function request(Request $request, int $client): JsonResponse
    {
        $this->enabled();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $request->validate(['confirmed' => ['required', 'accepted'], 'numeroDocumento' => ['required', 'string', 'max:17']]);
        $model = $this->client($client);
        if ($model === null) {
            return $this->notFound();
        }
        $this->allows($request, $model, TenantPermission::FiscalSyncTrigger);
        try {
            return response()->json(['data' => $this->receipts->request(
                $this->tenant->tenant(),
                $model,
                $request->string('numeroDocumento')->toString(),
                $request->user()?->id,
            )], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_DOCUMENT_NUMBER'], 422);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Comprovante indisponível para consulta.', 'code' => 'PAGTOWEB_UNAVAILABLE'], 422);
        }
    }

    public function download(Request $request, int $client, int $receipt): Response|JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $model = $this->client($client);
        if ($model === null) {
            return $this->notFound();
        }
        $this->allows($request, $model, TenantPermission::FiscalMonitoringView);
        $document = PagtowebArrecadacaoReceipt::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->tenant()->id)
            ->where('client_id', $model->id)
            ->whereKey($receipt)
            ->first();
        if ($document === null) {
            return $this->notFound();
        }

        try {
            $bytes = $this->projector->readAuthorized($document, $this->tenant->tenant()->id);
        } catch (RuntimeException) {
            return $this->notFound();
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'attachment; filename="comprovante-pagtoweb-'.$document->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function client(int $id): ?Client
    {
        return Client::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->tenant()->id)
            ->whereKey($id)
            ->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Cliente ou comprovante não encontrado no escritório atual.', 'code' => 'RESOURCE_NOT_FOUND'], 404);
    }

    private function allows(Request $request, Client $client, TenantPermission $permission): void
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->auth->allows($user, $permission, $client)) {
            abort(403, 'Sem permissão para esta operação.');
        }
    }

    private function enabled(): void
    {
        $tenant = $this->tenant->tenant();
        if (! FeatureFlags::isModuleEnabled('guides', $tenant->id) && ! (bool) config('fiscal_monitoring.enabled', false)) {
            abort(403, 'Módulo guias desabilitado.');
        }
    }

    private function rejectClientTenantId(Request $request): ?JsonResponse
    {
        $supplied = $request->attributes->get(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED) === true
            || $this->hasTenantId($request->query->all())
            || $this->hasTenantId($request->request->all())
            || ($request->isJson() && $request->json() !== null && $this->hasTenantId($request->json()->all()));

        return $supplied
            ? response()->json(['message' => 'tenant_id não é aceito; o escritório é obtido do contexto autenticado.', 'code' => 'CLIENT_TENANT_ID_REJECTED'], 422)
            : null;
    }

    /** @param array<array-key, mixed> $values */
    private function hasTenantId(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && strtolower($key) === 'tenant_id') {
                return true;
            }
            if (is_array($value) && $this->hasTenantId($value)) {
                return true;
            }
        }

        return false;
    }
}
