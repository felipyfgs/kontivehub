<?php

namespace App\Models;

use App\Enums\DctfwebCategory;
use App\Enums\DctfwebConsultOutcome;
use App\Enums\DctfwebDeclarationState;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Observação imutável de consulta DCTFWeb (sem payload fiscal / Base64).
 */
#[Fillable([
    'office_id',
    'client_id',
    'declaration_id',
    'run_id',
    'category',
    'period_key',
    'ano_pa',
    'mes_pa',
    'outcome',
    'provenance',
    'declaration_state',
    'productive',
    'document_stored',
    'reason',
    'sanitized_message',
    'observed_at',
    'metadata',
])]
class DctfwebConsultObservation extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'category' => DctfwebCategory::class,
            'outcome' => DctfwebConsultOutcome::class,
            'declaration_state' => DctfwebDeclarationState::class,
            'productive' => 'boolean',
            'document_stored' => 'boolean',
            'observed_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(DctfwebDeclaration::class, 'declaration_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'run_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'declaration_id' => $this->declaration_id,
            'run_id' => $this->run_id,
            'category' => $this->category?->value ?? (string) $this->category,
            'period_key' => $this->period_key,
            'ano_pa' => $this->ano_pa,
            'mes_pa' => $this->mes_pa,
            'outcome' => $this->outcome?->value ?? (string) $this->outcome,
            'provenance' => $this->provenance,
            'declaration_state' => $this->declaration_state?->value,
            'productive' => (bool) $this->productive,
            'document_stored' => (bool) $this->document_stored,
            'reason' => $this->reason,
            'sanitized_message' => $this->sanitized_message,
            'observed_at' => $this->observed_at?->toIso8601String(),
        ];
    }
}
