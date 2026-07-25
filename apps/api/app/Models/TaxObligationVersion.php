<?php

namespace App\Models;

use App\Enums\TaxObligationApplicability;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versão de regra de obrigação (aplicabilidade, fonte, vigência).
 */
#[Fillable([
    'obligation_definition_id',
    'version',
    'rule_key',
    'default_applicability',
    'rule_basis',
    'source_ref',
    'timezone',
    'effective_from',
    'effective_to',
    'is_current',
    'metadata',
])]
class TaxObligationVersion extends Model
{
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'default_applicability' => TaxObligationApplicability::class,
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'is_current' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(TaxObligationDefinition::class, 'obligation_definition_id');
    }

    public function regimeRules(): HasMany
    {
        return $this->hasMany(TaxObligationRegimeRule::class, 'obligation_version_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'obligation_definition_id' => $this->obligation_definition_id,
            'version' => $this->version,
            'rule_key' => $this->rule_key,
            'default_applicability' => $this->default_applicability?->value,
            'rule_basis' => $this->rule_basis,
            'source_ref' => $this->source_ref,
            'timezone' => $this->timezone,
            'effective_from' => $this->effective_from?->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
            'is_current' => $this->is_current,
        ];
    }
}
