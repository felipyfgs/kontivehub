<?php

namespace App\Models;

use App\Enums\SerproConsumptionClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Classificação versionada de operação SERPRO por vigência.
 */
#[Fillable([
    'operation_key',
    'system_code',
    'service_code',
    'operation_code',
    'consumption_class',
    'is_essential',
    'effective_from',
    'effective_to',
    'label',
    'notes',
])]
class SerproOperationCatalogEntry extends Model
{
    protected $table = 'serpro_operation_catalog';

    protected function casts(): array
    {
        return [
            'consumption_class' => SerproConsumptionClass::class,
            'is_essential' => 'boolean',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    public function isEffectiveAt(Carbon|string|null $at = null): bool
    {
        $at = $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : now());

        if ($this->effective_from && $at->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_to !== null && $at->gt($this->effective_to)) {
            return false;
        }

        return true;
    }
}
