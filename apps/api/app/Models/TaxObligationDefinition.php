<?php

namespace App\Models;

use App\Enums\TaxPeriodGranularity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Catálogo global de obrigações declaratórias (sem office_id).
 */
#[Fillable([
    'code',
    'name',
    'description',
    'fiscal_category_code',
    'module_key',
    'system_code',
    'service_code',
    'period_granularity',
    'default_timezone',
    'is_active',
    'sort_order',
    'supported_operations',
    'metadata',
])]
class TaxObligationDefinition extends Model
{
    protected function casts(): array
    {
        return [
            'period_granularity' => TaxPeriodGranularity::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'supported_operations' => 'array',
            'metadata' => 'array',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TaxObligationVersion::class, 'obligation_definition_id');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(TaxObligationVersion::class, 'obligation_definition_id')
            ->where('is_current', true)
            ->latestOfMany('version');
    }

    public function deadlineRules(): HasMany
    {
        return $this->hasMany(TaxDeadlineRule::class, 'obligation_definition_id');
    }

    public function projections(): HasMany
    {
        return $this->hasMany(TaxObligationProjection::class, 'obligation_definition_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'fiscal_category_code' => $this->fiscal_category_code,
            'module_key' => $this->module_key,
            'system_code' => $this->system_code,
            'service_code' => $this->service_code,
            'period_granularity' => $this->period_granularity?->value,
            'default_timezone' => $this->default_timezone,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'supported_operations' => $this->supported_operations ?? [],
        ];
    }
}
