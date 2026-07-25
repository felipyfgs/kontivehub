<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use App\Http\Controllers\Controller;
use App\Models\FiscalModuleControl;
use App\Models\Office;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Fiscal\Availability\FiscalModuleAvailabilityService;
use App\Services\Fiscal\Availability\FiscalModuleControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FiscalModuleControlController extends Controller
{
    public function __construct(
        private readonly FiscalModuleAvailabilityService $availability,
        private readonly FiscalModuleControlService $controls,
        private readonly RecentPasswordConfirmationGate $passwordGate,
    ) {}

    public function globalIndex(): JsonResponse
    {
        return response()->json([
            'data' => [
                'profile' => (string) config('fiscal.profile'),
                'kill_switch' => (bool) config('fiscal.kill_switch'),
                'modules' => array_map(fn (FiscalControlModule $module): array => $this->modulePayload($module), FiscalControlModule::cases()),
            ],
        ]);
    }

    public function officeIndex(Office $office): JsonResponse
    {
        return response()->json([
            'data' => [
                'office' => [
                    'id' => $office->id,
                    'name' => $office->name,
                    'slug' => $office->slug,
                    'is_active' => $office->is_active,
                ],
                'profile' => (string) config('fiscal.profile'),
                'kill_switch' => (bool) config('fiscal.kill_switch'),
                'modules' => array_map(fn (FiscalControlModule $module): array => $this->modulePayload($module, $office), FiscalControlModule::cases()),
            ],
        ]);
    }

    public function updateGlobal(Request $request, string $module): JsonResponse
    {
        return $this->update($request, $this->resolveModule($module), FiscalModuleControlScope::Global, null);
    }

    public function updateOffice(Request $request, Office $office, string $module): JsonResponse
    {
        return $this->update($request, $this->resolveModule($module), FiscalModuleControlScope::Office, $office);
    }

    private function update(
        Request $request,
        FiscalControlModule $module,
        FiscalModuleControlScope $scope,
        ?Office $office,
    ): JsonResponse {
        $validated = $request->validate([
            'restricted' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $recent = $this->passwordGate->isRecentlyConfirmed($actor, $request);
        if (! $validated['restricted'] && ! $recent) {
            return response()->json([
                'message' => 'Liberar um módulo exige reconfirmação de senha recente.',
                'code' => 'password_confirmation_required',
            ], 403);
        }

        $this->controls->setRestriction(
            $module,
            $scope,
            $office,
            (bool) $validated['restricted'],
            (string) $validated['reason'],
            $actor,
            $recent,
        );

        return response()->json([
            'data' => $this->modulePayload($module, $office),
            'message' => $validated['restricted']
                ? 'Módulo restringido imediatamente.'
                : 'Módulo liberado; a sincronização de recuperação será agendada.',
        ]);
    }

    /** @return array<string, mixed> */
    private function modulePayload(FiscalControlModule $module, ?Office $office = null): array
    {
        $global = $this->findControl($module, FiscalModuleControlScope::Global);
        $local = $office !== null
            ? $this->findControl($module, FiscalModuleControlScope::Office, (int) $office->id)
            : null;

        return array_merge($this->availability->resolve($module, $office)->toArray(), [
            'global_restriction' => $this->controlPayload($global),
            'office_restriction' => $this->controlPayload($local),
            'blocked_jobs_count' => (int) (($global?->blocked_jobs_count ?? 0) + ($local?->blocked_jobs_count ?? 0)),
        ]);
    }

    private function findControl(
        FiscalControlModule $module,
        FiscalModuleControlScope $scope,
        ?int $officeId = null,
    ): ?FiscalModuleControl {
        return FiscalModuleControl::query()
            ->with('updatedBy:id,name')
            ->where('control_key', FiscalModuleControl::controlKey($module, $scope, $officeId))
            ->first();
    }

    /** @return array<string, mixed>|null */
    private function controlPayload(?FiscalModuleControl $control): ?array
    {
        if ($control === null) {
            return null;
        }

        return [
            'id' => $control->id,
            'restricted' => $control->restricted,
            'reason' => $control->reason,
            'updated_by' => $control->updatedBy ? [
                'id' => $control->updatedBy->id,
                'name' => $control->updatedBy->name,
            ] : null,
            'restricted_at' => $control->restricted_at?->toIso8601String(),
            'updated_at' => $control->updated_at?->toIso8601String(),
            'blocked_jobs_count' => $control->blocked_jobs_count,
        ];
    }

    private function resolveModule(string $module): FiscalControlModule
    {
        $resolved = FiscalControlModule::tryFrom(strtolower(trim($module)));
        abort_if($resolved === null, 404, 'Módulo fiscal não encontrado.');

        return $resolved;
    }
}
