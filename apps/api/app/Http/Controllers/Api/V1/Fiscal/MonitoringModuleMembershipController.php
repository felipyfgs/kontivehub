<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\FiscalModuleKey;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\FiscalMonitoring\MonitoringModuleMembershipService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Include/exclude de clientes na carteira de monitoramento (opt-out tenant-scoped).
 */
class MonitoringModuleMembershipController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly MonitoringModuleMembershipService $membership,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $tenant = $this->currentTenant->tenant();

        $data = $request->validate([
            'module' => ['required', 'string', Rule::in(FiscalModuleKey::values())],
            'submodule' => ['nullable', 'string', 'max:64'],
        ]);

        $module = FiscalModuleKey::tryFromRoute($data['module'])
            ?? FiscalModuleKey::tryFrom($data['module']);
        if ($module === null || $module === FiscalModuleKey::Dashboard) {
            return response()->json(['message' => 'Módulo inválido.'], 422);
        }

        $items = $this->membership->listExclusions(
            $tenant,
            $module,
            $data['submodule'] ?? null,
        );

        return response()->json([
            'data' => $items->map(fn ($row) => $row->toPublicArray())->values(),
        ]);
    }

    public function exclude(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $tenant = $this->currentTenant->tenant();
        $actor = $request->user();

        $data = $request->validate([
            'module' => ['required', 'string', Rule::in(FiscalModuleKey::values())],
            'submodule' => ['nullable', 'string', 'max:64'],
            'client_ids' => ['required', 'array', 'min:1', 'max:200'],
            'client_ids.*' => ['integer', 'min:1'],
        ]);

        $module = FiscalModuleKey::tryFromRoute($data['module'])
            ?? FiscalModuleKey::tryFrom($data['module']);
        if ($module === null || $module === FiscalModuleKey::Dashboard) {
            return response()->json(['message' => 'Módulo inválido.'], 422);
        }

        try {
            $result = $this->membership->exclude(
                $tenant,
                $module,
                $data['client_ids'],
                $data['submodule'] ?? null,
                $actor?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function include(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $tenant = $this->currentTenant->tenant();

        $data = $request->validate([
            'module' => ['required', 'string', Rule::in(FiscalModuleKey::values())],
            'submodule' => ['nullable', 'string', 'max:64'],
            'client_ids' => ['required', 'array', 'min:1', 'max:200'],
            'client_ids.*' => ['integer', 'min:1'],
        ]);

        $module = FiscalModuleKey::tryFromRoute($data['module'])
            ?? FiscalModuleKey::tryFrom($data['module']);
        if ($module === null || $module === FiscalModuleKey::Dashboard) {
            return response()->json(['message' => 'Módulo inválido.'], 422);
        }

        try {
            $result = $this->membership->include(
                $tenant,
                $module,
                $data['client_ids'],
                $data['submodule'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $status = $result['errors'] !== [] && $result['included'] === 0 ? 422 : 200;

        return response()->json(['data' => $result], $status);
    }

    private function assertCanRead(): void
    {
        $actor = request()->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalMonitoringView)) {
            abort(403, 'Perfil não resolvido.');
        }
    }

    private function assertCanWrite(): void
    {
        $actor = request()->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::ClientsManage)) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }
}
