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
    'office_id',
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

            if ($scope === FiscalModuleControlScope::Global && $control->office_id !== null) {
                throw new InvalidArgumentException('Controle GLOBAL não pode possuir office_id.');
            }
            if ($scope === FiscalModuleControlScope::Office && $control->office_id === null) {
                throw new InvalidArgumentException('Controle OFFICE exige office_id.');
            }

            $control->control_key = self::controlKey($module, $scope, $control->office_id);
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
        ?int $officeId,
    ): string {
        return $scope === FiscalModuleControlScope::Global
            ? "GLOBAL:{$module->value}"
            : "OFFICE:{$officeId}:{$module->value}";
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
