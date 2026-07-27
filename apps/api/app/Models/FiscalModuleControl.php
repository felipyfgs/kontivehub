<?php

namespace App\Models;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable([
    'module_key',
    'scope',
    'tenant_id',
    'restricted',
    'reason',
    'updated_by_user_id',
    'restricted_at',
    'blocked_jobs_count',
])]
class FiscalModuleControl extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $control): void {
            $module = $control->module_key instanceof FiscalControlModule
                ? $control->module_key
                : FiscalControlModule::from((string) $control->module_key);
            $scope = $control->scope instanceof FiscalModuleControlScope
                ? $control->scope
                : FiscalModuleControlScope::from((string) $control->scope);

            if ($scope === FiscalModuleControlScope::Global && $control->tenant_id !== null) {
                throw new InvalidArgumentException('Controle GLOBAL não pode possuir tenant_id.');
            }
            if ($scope === FiscalModuleControlScope::Tenant && $control->tenant_id === null) {
                throw new InvalidArgumentException('Controle TENANT exige tenant_id.');
            }

            $control->control_key = self::controlKey($module, $scope, $control->tenant_id);
            $control->restricted_at = $control->restricted
                ? ($control->restricted_at ?? now())
                : null;
        });
    }

    protected function casts(): array
    {
        return [
            'module_key' => FiscalControlModule::class,
            'scope' => FiscalModuleControlScope::class,
            'restricted' => 'boolean',
            'restricted_at' => 'immutable_datetime',
            'blocked_jobs_count' => 'integer',
        ];
    }

    public static function controlKey(
        FiscalControlModule $module,
        FiscalModuleControlScope $scope,
        ?int $tenantId,
    ): string {
        return $scope === FiscalModuleControlScope::Global
            ? "GLOBAL:{$module->value}"
            : "TENANT:{$tenantId}:{$module->value}";
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
