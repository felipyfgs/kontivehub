<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Dctfweb\MitCommunicationService;
use App\Services\Fiscal\Fgts\FgtsCommunicationService;
use App\Services\Fiscal\Sitfis\SitfisCommunicationService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Comunicação de carteiras transversais (SITFIS / FGTS / MIT).
 */
class MonitoringModuleCommunicationController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TenantAuthorization $authorization,
        private readonly SitfisCommunicationService $sitfis,
        private readonly FgtsCommunicationService $fgts,
        private readonly MitCommunicationService $mit,
    ) {}

    public function updatePreferences(Request $request, string $module, int $client): JsonResponse
    {
        $this->assertCanManage();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient($tenant->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }
        $data = $request->validate([
            'email_enabled' => ['required', 'boolean'],
            'whatsapp_enabled' => ['required', 'boolean'],
            'automatic_requested' => ['required', 'boolean'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        try {
            $pref = $this->service($module)->updatePreferences($tenant, $model, $actor, $data);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json([
            'data' => $this->service($module)->summary($tenant, $model),
            'preference_id' => $pref->id,
        ]);
    }

    public function preview(Request $request, string $module, int $client): JsonResponse
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

        return response()->json(['data' => $this->service($module)->preview($tenant, $model)]);
    }

    public function tracking(Request $request, string $module, int $client): JsonResponse
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

        return response()->json(['data' => $this->service($module)->tracking($tenant, $model)]);
    }

    public function send(Request $request, string $module, int $client): JsonResponse
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
            $data = $this->service($module)->requestSend($tenant, $model, $actor, $input['period_key'] ?? null);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['data' => $data]);
    }

    private function service(string $module): SitfisCommunicationService|FgtsCommunicationService|MitCommunicationService
    {
        return match (strtolower($module)) {
            'sitfis' => $this->sitfis,
            'fgts' => $this->fgts,
            'mit' => $this->mit,
            default => abort(404, 'Módulo de comunicação desconhecido.'),
        };
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
        if (! $suppliedAtTopLevel) {
            return null;
        }

        return response()->json([
            'message' => 'tenant_id não é aceito; o escritório é obtido do contexto autenticado.',
            'code' => 'CLIENT_TENANT_ID_REJECTED',
        ], 422);
    }

    private function assertCanRead(): void
    {
        $this->assertPermission(TenantPermission::FiscalMonitoringView);
    }

    private function assertCanSync(): void
    {
        $this->assertPermission(TenantPermission::FiscalSyncTrigger, 'Sem permissão de sincronização.');
    }

    private function assertCanManage(): void
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
