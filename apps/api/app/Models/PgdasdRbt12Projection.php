<?php

namespace App\Models;

use App\Enums\PgdasdRbt12Status;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'projection_id',
    'source_reference_key',
    'source_das_number',
    'source_declaration_number',
    'source_transmitted_at',
    'internal_market_cents',
    'external_market_cents',
    'total_cents',
    'status',
    'attempted_at',
    'extracted_at',
    'sanitized_error',
    'parser_version',
    'source_artifact_id',
    'source_run_id',
    'metadata',
])]
class PgdasdRbt12Projection extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'status' => PgdasdRbt12Status::class,
            'source_transmitted_at' => 'immutable_datetime',
            'internal_market_cents' => 'integer',
            'external_market_cents' => 'integer',
            'total_cents' => 'integer',
            'attempted_at' => 'immutable_datetime',
            'extracted_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function projection(): BelongsTo
    {
        return $this->belongsTo(TaxObligationProjection::class, 'projection_id');
    }

    public function sourceArtifact(): BelongsTo
    {
        return $this->belongsTo(PgdasdArtifact::class, 'source_artifact_id');
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'source_run_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $value = null;
        if ($this->total_cents !== null) {
            $value = number_format(((int) $this->total_cents) / 100, 2, '.', '');
        }

        return [
            'id' => $this->id,
            'period_key' => $this->relationLoaded('projection')
                ? $this->projection?->period_key
                : (is_array($this->metadata) ? ($this->metadata['period_key'] ?? null) : null),
            'periodo_apuracao' => is_array($this->metadata) ? ($this->metadata['periodo_apuracao'] ?? null) : null,
            'status' => $this->status?->value ?? $this->getRawOriginal('status'),
            'rbt12_value' => $value,
            'total_cents' => $this->total_cents,
            'internal_market_cents' => $this->internal_market_cents,
            'external_market_cents' => $this->external_market_cents,
            'composition' => [
                'internal_market_cents' => $this->internal_market_cents,
                'external_market_cents' => $this->external_market_cents,
                'total_cents' => $this->total_cents,
            ],
            'rpa_cents' => is_array($this->metadata) && isset($this->metadata['rpa_cents'])
                ? (int) $this->metadata['rpa_cents']
                : null,
            'origin' => [
                'das_number' => $this->source_das_number,
                'declaration_number' => $this->source_declaration_number,
                'declaration_transmitted_at' => $this->source_transmitted_at?->toIso8601String(),
            ],
            'parser_version' => $this->parser_version,
            'unavailable_reason' => $this->sanitized_error,
            'numero_das' => $this->source_das_number,
            'numero_declaracao' => $this->source_declaration_number,
            'resolved_at' => $this->extracted_at?->toIso8601String(),
            'extracted_at' => $this->extracted_at?->toIso8601String(),
        ];
    }
}
