<?php

namespace App\Models;

use App\Enums\SerproBillableClass;
use App\Enums\SerproEnvironment;
use App\Enums\SerproFunctionalRoute;
use App\Enums\SerproOfficialState;
use App\Enums\SerproPlatformSupport;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'catalog_version',
    'environment',
    'operation_key',
    'solution_code',
    'service_code',
    'operation_code',
    'id_sistema',
    'id_servico',
    'versao_sistema',
    'functional_route',
    'official_state',
    'platform_support',
    'dados_mode',
    'label',
    'is_mutating',
    'is_enabled',
    'required_proxy_power',
    'billable_class',
    'cache_ttl_seconds',
    'rate_limit_per_minute',
    'coverage',
    'metadata',
    'effective_from',
    'effective_to',
])]
class SerproServiceCatalogEntry extends Model
{
    protected function casts(): array
    {
        return [
            'environment' => SerproEnvironment::class,
            'billable_class' => SerproBillableClass::class,
            'official_state' => SerproOfficialState::class,
            'platform_support' => SerproPlatformSupport::class,
            'functional_route' => SerproFunctionalRoute::class,
            'is_mutating' => 'boolean',
            'is_enabled' => 'boolean',
            'metadata' => 'array',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'catalog_version' => $this->catalog_version,
            'environment' => $this->environment?->value,
            'operation_key' => $this->operation_key,
            'solution_code' => $this->solution_code,
            'service_code' => $this->service_code,
            'operation_code' => $this->operation_code,
            'id_sistema' => $this->id_sistema,
            'id_servico' => $this->id_servico,
            'versao_sistema' => $this->versao_sistema,
            'functional_route' => $this->functional_route?->value ?? $this->functional_route,
            'official_state' => $this->official_state?->value ?? $this->official_state,
            'platform_support' => $this->platform_support?->value ?? $this->platform_support,
            'label' => $this->label,
            'is_mutating' => $this->is_mutating,
            'is_enabled' => $this->is_enabled,
            'required_proxy_power' => $this->required_proxy_power,
            'billable_class' => $this->billable_class?->value,
            'cache_ttl_seconds' => $this->cache_ttl_seconds,
            'rate_limit_per_minute' => $this->rate_limit_per_minute,
            'coverage' => $this->coverage,
        ];
    }
}
