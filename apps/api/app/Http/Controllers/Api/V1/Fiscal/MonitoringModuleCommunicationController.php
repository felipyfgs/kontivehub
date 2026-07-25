<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOfficeContext;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Dctfweb\MitCommunicationService;
use App\Services\Fiscal\Fgts\FgtsCommunicationService;
use App\Services\Fiscal\Sitfis\SitfisCommunicationService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Comunicação de carteiras transversais (SITFIS / FGTS / MIT).
 */
class MonitoringModuleCommunicationController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly TenantAuthorization $authorization,
        private readonly SitfisCommunicationService $sitfis,
        private readonly FgtsCommunicationService $fgts,
        private readonly MitCommunicationService $mit,
    ) {}

    public function updatePreferences(Request $request, string $module, int $client): JsonResponse
    {
        $this->assertCanManage();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();
        $model = $this->findClient($office->id, $client);
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
            $pref = $this->service($module)->updatePreferences($office, $model, $actor, $data);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json([
            'data' => $this->service($module)->summary($office, $model),
            'preference_id' => $pref->id,
        ]);
    }

    public function preview(Request $request, string $module, int $client): JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();
        $model = $this->findClient($office->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json(['data' => $this->service($module)->preview($office, $model)]);
    }

    public function tracking(Request $request, string $module, int $client): JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();
        $model = $this->findClient($office->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json(['data' => $this->service($module)->tracking($office, $model)]);
    }

    public function send(Request $request, string $module, int $client): JsonResponse
    {
        $this->assertCanSync();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();
        $model = $this->findClient($office->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }
        /** @var User $actor */
        $actor = $request->user();
        $input = $request->validate(['period_key' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/']]);
        try {
            $data = $this->service($module)->requestSend($office, $model, $actor, $input['period_key'] ?? null);
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

    private function findClient(int $officeId, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereKey($clientId)
            ->first();
    }

    private function rejectClientOfficeId(Request $request): ?JsonResponse
    {
        $suppliedAtTopLevel = $request->attributes->get(
            EnsureOfficeContext::CLIENT_OFFICE_ID_SUPPLIED,
        ) === true;
        if (! $suppliedAtTopLevel) {
            return null;
        }

        return response()->json([
            'message' => 'office_id não é aceito; o escritório é obtido do contexto autenticado.',
            'code' => 'CLIENT_OFFICE_ID_REJECTED',
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
