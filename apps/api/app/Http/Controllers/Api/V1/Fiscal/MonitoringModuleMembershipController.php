<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\FiscalModuleKey;
use App\Enums\OfficeRole;
use App\Http\Controllers\Controller;
use App\Services\FiscalMonitoring\MonitoringModuleMembershipService;
use App\Support\CurrentOffice;
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
        private readonly CurrentOffice $currentOffice,
        private readonly MonitoringModuleMembershipService $membership,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();

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
            $office,
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
        $office = $this->currentOffice->office();
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
                $office,
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
        $office = $this->currentOffice->office();

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
                $office,
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
        $role = $this->currentOffice->role();
        if ($role === null) {
            abort(403, 'Perfil não resolvido.');
        }
    }

    private function assertCanWrite(): void
    {
        $role = $this->currentOffice->role();
        if ($role === null || ! in_array($role, [OfficeRole::Admin, OfficeRole::Operator], true)) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }
}
