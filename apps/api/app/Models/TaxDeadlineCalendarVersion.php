<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versão de calendário oficial de prazos (global).
 */
#[Fillable([
    'code',
    'version',
    'label',
    'timezone',
    'effective_from',
    'effective_to',
    'is_current',
    'source_ref',
    'notes',
    'metadata',
])]
class TaxDeadlineCalendarVersion extends Model
{
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'is_current' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(TaxDeadlineRule::class, 'calendar_version_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'version' => $this->version,
            'label' => $this->label,
            'timezone' => $this->timezone,
            'effective_from' => $this->effective_from?->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
            'is_current' => $this->is_current,
            'source_ref' => $this->source_ref,
            'notes' => $this->notes,
        ];
    }
}
