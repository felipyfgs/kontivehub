<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'calendar_year',
    'declaration_type',
    'last_observed_at',
    'last_observation_id',
    'last_run_id',
    'defis_declaration_reference_id',
    'source_provenance',
])]
class DefisDeclarationProjection extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return ['last_observed_at' => 'immutable_datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lastObservation(): BelongsTo
    {
        return $this->belongsTo(DefisDeclarationObservation::class, 'last_observation_id');
    }

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'last_run_id');
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'calendar_year' => $this->calendar_year,
            'declaration_type' => $this->declaration_type,
            'observed_at' => $this->last_observed_at?->toIso8601String(),
            'source_provenance' => $this->source_provenance,
        ];
    }
}
