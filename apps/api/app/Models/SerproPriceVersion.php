<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Versão de tabela de preços SERPRO (plano de controle — sem office_id).
 * Somente eligibility=PRODUCTION e authorizes_production=true liberam egress real.
 */
#[Fillable([
    'version_code',
    'name',
    'effective_from',
    'effective_to',
    'is_active',
    'currency',
    'notes',
    'source_url',
    'source_hash',
    'source_revision',
    'eligibility',
    'authorizes_production',
    'billing_cycle_kind',
])]
class SerproPriceVersion extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'is_active' => 'boolean',
            'authorizes_production' => 'boolean',
        ];
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(SerproPriceTier::class, 'price_version_id');
    }

    public function isEffectiveAt(Carbon|string|null $at = null): bool
    {
        $at = $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : now());

        if (! $this->is_active) {
            return false;
        }

        if ($this->effective_from && $at->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_to !== null && $at->gt($this->effective_to)) {
            return false;
        }

        return true;
    }

    public function authorizesProductiveEgress(): bool
    {
        return (bool) $this->authorizes_production
            && strtoupper((string) $this->eligibility) === 'PRODUCTION'
            && $this->is_active;
    }
}
