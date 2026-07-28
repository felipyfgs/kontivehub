<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\ReadMonitoringModuleCommunicationAction;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\Fiscal\Monitoring\ViewMonitoringModuleCommunicationRequest;
use App\Http\Requests\Fiscal\Mutations\OptionalPeriodKeyRequest;
use App\Http\Requests\Fiscal\Mutations\UpdateCommunicationPreferencesRequest;
use App\Http\Resources\Fiscal\MonitoringModuleCommunicationResource;
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

    public function updatePreferences(
        UpdateCommunicationPreferencesRequest $request,
        string $module,
        int $client,
    ): JsonResponse {
        $this->assertCanManage();
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
            $pref = $this->service($module)->updatePreferences(
                $tenant,
                $model,
                $actor,
                $request->preferences(),
            );
        } catch (HttpException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], $e->getStatusCode());
        }

        return response()->json([
            'data' => $this->service($module)->summary($tenant, $model),
            'preference_id' => $pref->id,
        ]);
    }

    public function preview(
        ViewMonitoringModuleCommunicationRequest $request,
        string $module,
        int $client,
        ReadMonitoringModuleCommunicationAction $read,
    ): MonitoringModuleCommunicationResource {
        return new MonitoringModuleCommunicationResource(
            $read->preview($request->readData()),
        );
    }

    public function tracking(
        ViewMonitoringModuleCommunicationRequest $request,
        string $module,
        int $client,
        ReadMonitoringModuleCommunicationAction $read,
    ): MonitoringModuleCommunicationResource {
        return new MonitoringModuleCommunicationResource(
            $read->tracking($request->readData()),
        );
    }

    public function send(
        OptionalPeriodKeyRequest $request,
        string $module,
        int $client,
    ): JsonResponse {
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
            $data = $this->service($module)->requestSend(
                $tenant,
                $model,
                $actor,
                $request->periodKey(),
            );
        } catch (HttpException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], $e->getStatusCode());
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
