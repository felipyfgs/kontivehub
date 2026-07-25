<?php

namespace App\Services\Fiscal\Availability;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use App\Events\FiscalModuleReleased;
use App\Exceptions\RecentPasswordRequiredException;
use App\Jobs\Fiscal\RecoverFiscalModuleJob;
use App\Models\FiscalModuleControl;
use App\Models\Office;
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
        ?Office $office,
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
        if ($scope === FiscalModuleControlScope::Office && $office === null) {
            throw ValidationException::withMessages(['office' => 'Escritório obrigatório para restrição OFFICE.']);
        }
        if ($scope === FiscalModuleControlScope::Global) {
            $office = null;
        }

        $control = DB::transaction(function () use ($module, $scope, $office, $restricted, $reason, $actor): FiscalModuleControl {
            $key = FiscalModuleControl::controlKey($module, $scope, $office?->id);
            $control = FiscalModuleControl::query()->where('control_key', $key)->lockForUpdate()->first()
                ?? new FiscalModuleControl;
            $wasRestricted = $control->exists && (bool) $control->restricted;
            $control->fill([
                'module_key' => $module,
                'scope' => $scope,
                'office_id' => $office?->id,
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
                    'office_id' => $office?->id,
                    'reason' => $reason,
                    'previously_restricted' => $wasRestricted,
                ],
                $actor->id,
                $office?->id,
            );

            if ($wasRestricted && ! $restricted) {
                DB::afterCommit(static function () use ($module, $scope, $office, $actor): void {
                    FiscalModuleReleased::dispatch($module, $scope, $office?->id, (int) $actor->id);
                    RecoverFiscalModuleJob::dispatch($module->value, $office?->id, (int) $actor->id);
                });
            }

            return $control;
        });

        return $control->refresh();
    }

    public function recordBlockedJob(
        FiscalControlModule|string $module,
        Office $office,
        string $reasonCode,
        ?int $jobSubjectId = null,
    ): void {
        $module = is_string($module) ? FiscalControlModule::fromRuntimeKey($module) : $module;
        $globalKey = FiscalModuleControl::controlKey($module, FiscalModuleControlScope::Global, null);
        $officeKey = FiscalModuleControl::controlKey($module, FiscalModuleControlScope::Office, (int) $office->id);

        $control = FiscalModuleControl::query()
            ->where('restricted', true)
            ->whereIn('control_key', [$globalKey, $officeKey])
            ->orderByRaw('CASE WHEN scope = ? THEN 0 ELSE 1 END', [FiscalModuleControlScope::Global->value])
            ->first();
        $control?->increment('blocked_jobs_count');

        $this->audit->record('fiscal.module.job_blocked', 'BLOCKED', $control, [
            'module_key' => $module->value,
            'reason_code' => $reasonCode,
            'job_subject_id' => $jobSubjectId,
        ], officeId: (int) $office->id);
    }
}
