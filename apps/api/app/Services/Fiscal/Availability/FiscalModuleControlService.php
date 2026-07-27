<?php

namespace App\Services\Fiscal\Availability;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use App\Events\FiscalModuleReleased;
use App\Exceptions\RecentPasswordRequiredException;
use App\Jobs\Fiscal\RecoverFiscalModuleJob;
use App\Models\FiscalModuleControl;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FiscalModuleControlService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException|RecentPasswordRequiredException|ValidationException
     */
    public function setRestriction(
        FiscalControlModule $module,
        FiscalModuleControlScope $scope,
        ?Tenant $tenant,
        bool $restricted,
        string $reason,
        User $actor,
        bool $passwordRecentlyConfirmed,
    ): FiscalModuleControl {
        if (! $actor->isPlatformAdmin()) {
            throw new AuthorizationException('Somente PLATFORM_ADMIN pode alterar restrições fiscais.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Informe o motivo da alteração.']);
        }
        if (! $restricted && ! $passwordRecentlyConfirmed) {
            throw new RecentPasswordRequiredException;
        }
        if ($scope === FiscalModuleControlScope::Tenant && $tenant === null) {
            throw ValidationException::withMessages(['tenant' => 'Escritório obrigatório para restrição TENANT.']);
        }
        if ($scope === FiscalModuleControlScope::Global) {
            $tenant = null;
        }

        $control = DB::transaction(function () use ($module, $scope, $tenant, $restricted, $reason, $actor): FiscalModuleControl {
            $key = FiscalModuleControl::controlKey($module, $scope, $tenant?->id);
            $control = FiscalModuleControl::query()->where('control_key', $key)->lockForUpdate()->first()
                ?? new FiscalModuleControl;
            $wasRestricted = $control->exists && (bool) $control->restricted;
            $control->fill([
                'module_key' => $module,
                'scope' => $scope,
                'tenant_id' => $tenant?->id,
                'restricted' => $restricted,
                'reason' => $reason,
                'updated_by_user_id' => $actor->id,
                'restricted_at' => $restricted ? now() : null,
            ]);
            $control->save();

            $this->audit->record(
                $restricted ? 'fiscal.module.restricted' : 'fiscal.module.released',
                'SUCCESS',
                $control,
                [
                    'module_key' => $module->value,
                    'scope' => $scope->value,
                    'tenant_id' => $tenant?->id,
                    'reason' => $reason,
                    'previously_restricted' => $wasRestricted,
                ],
                $actor->id,
                $tenant?->id,
            );

            if ($wasRestricted && ! $restricted) {
                DB::afterCommit(static function () use ($module, $scope, $tenant, $actor): void {
                    FiscalModuleReleased::dispatch($module, $scope, $tenant?->id, (int) $actor->id);
                    RecoverFiscalModuleJob::dispatch($module->value, $tenant?->id, (int) $actor->id);
                });
            }

            return $control;
        });

        return $control->refresh();
    }

    public function recordBlockedJob(
        FiscalControlModule|string $module,
        Tenant $tenant,
        string $reasonCode,
        ?int $jobSubjectId = null,
    ): void {
        $module = is_string($module) ? FiscalControlModule::fromRuntimeKey($module) : $module;
        $globalKey = FiscalModuleControl::controlKey($module, FiscalModuleControlScope::Global, null);
        $tenantKey = FiscalModuleControl::controlKey($module, FiscalModuleControlScope::Tenant, (int) $tenant->id);

        $control = FiscalModuleControl::query()
            ->where('restricted', true)
            ->whereIn('control_key', [$globalKey, $tenantKey])
            ->orderByRaw('CASE WHEN scope = ? THEN 0 ELSE 1 END', [FiscalModuleControlScope::Global->value])
            ->first();
        $control?->increment('blocked_jobs_count');

        $this->audit->record('fiscal.module.job_blocked', 'BLOCKED', $control, [
            'module_key' => $module->value,
            'reason_code' => $reasonCode,
            'job_subject_id' => $jobSubjectId,
        ], tenantId: (int) $tenant->id);
    }
}
