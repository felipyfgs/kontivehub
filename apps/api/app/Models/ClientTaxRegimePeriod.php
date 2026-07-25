<?php

namespace App\Models;

use App\Enums\TaxRegimeCode;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Projeção de regime por vigência (fonte: Regime de Apuração).
 * Competências SN e MEI usam estes períodos para aplicabilidade.
 */
#[Fillable([
    'office_id',
    'client_id',
    'regime_code',
    'effective_from',
    'effective_to',
    'source_system',
    'source_service',
    'source_run_id',
    'evidence_artifact_id',
    'observed_at',
    'metadata',
])]
class ClientTaxRegimePeriod extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'regime_code' => TaxRegimeCode::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'observed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'regime_code' => $this->regime_code?->value,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'source_system' => $this->source_system,
            'source_service' => $this->source_service,
            'observed_at' => $this->observed_at?->toIso8601String(),
        ];
    }
}
