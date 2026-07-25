<?php

namespace App\Models;

use App\Enums\DctfwebCategory;
use App\Enums\DctfwebDeclarationState;
use App\Enums\DctfwebTransmissionStatus;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalPaymentStatus;
use App\Enums\FiscalSituation;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'client_id',
    'competence_id',
    'period_key',
    'category',
    'declaration_type',
    'transmission_status',
    'situation',
    'declaration_state',
    'no_movement',
    'coverage',
    'receipt_number',
    'transmitted_at',
    'official_at',
    'last_productive_consulted_at',
    'calendar_verified',
    'calendar_version_code',
    'due_at',
    'state_reason',
    'evidence_version',
    'payment_status',
    'current_snapshot_id',
    'metadata',
])]
class DctfwebDeclaration extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'category' => DctfwebCategory::class,
            'transmission_status' => DctfwebTransmissionStatus::class,
            'situation' => FiscalSituation::class,
            'declaration_state' => DctfwebDeclarationState::class,
            'no_movement' => 'boolean',
            'coverage' => FiscalCoverage::class,
            'payment_status' => FiscalPaymentStatus::class,
            'transmitted_at' => 'immutable_datetime',
            'official_at' => 'immutable_datetime',
            'last_productive_consulted_at' => 'immutable_datetime',
            'calendar_verified' => 'boolean',
            'due_at' => 'immutable_datetime',
            'evidence_version' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function competence(): BelongsTo
    {
        return $this->belongsTo(FiscalCompetence::class, 'competence_id');
    }

    public function evidenceVersions(): HasMany
    {
        return $this->hasMany(DctfwebEvidenceVersion::class, 'declaration_id');
    }

    public function darfDocuments(): HasMany
    {
        return $this->hasMany(DctfwebDarfDocument::class, 'declaration_id');
    }

    public function consultObservations(): HasMany
    {
        return $this->hasMany(DctfwebConsultObservation::class, 'declaration_id');
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
            'competence_id' => $this->competence_id,
            'period_key' => $this->period_key,
            'category' => $this->category?->value ?? DctfwebCategory::default()->value,
            'declaration_type' => $this->declaration_type,
            'transmission_status' => $this->transmission_status?->value,
            'situation' => $this->situation?->value,
            'declaration_state' => $this->declaration_state?->value
                ?? DctfwebDeclarationState::Unverified->value,
            'no_movement' => $this->no_movement,
            'coverage' => $this->coverage?->value,
            'receipt_number' => $this->receipt_number,
            'transmitted_at' => $this->transmitted_at?->toIso8601String(),
            'official_at' => $this->official_at?->toIso8601String(),
            'last_productive_consulted_at' => $this->last_productive_consulted_at?->toIso8601String(),
            'calendar_verified' => (bool) $this->calendar_verified,
            'calendar_version_code' => $this->calendar_version_code,
            'due_at' => $this->due_at?->toIso8601String(),
            'state_reason' => $this->state_reason,
            'evidence_version' => $this->evidence_version,
            'payment_status' => $this->payment_status?->value,
            'current_snapshot_id' => $this->current_snapshot_id,
        ];
    }
}
