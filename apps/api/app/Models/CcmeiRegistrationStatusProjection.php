<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['office_id', 'client_id', 'status', 'enquadrado_mei', 'situation', 'count', 'last_valid_query_at', 'last_observation_id', 'last_run_id', 'source_provenance'])]
class CcmeiRegistrationStatusProjection extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return ['enquadrado_mei' => 'boolean', 'count' => 'integer', 'last_valid_query_at' => 'immutable_datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'last_run_id');
    }

    public function lastObservation(): BelongsTo
    {
        return $this->belongsTo(CcmeiRegistrationStatusObservation::class, 'last_observation_id');
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'status' => $this->status,
            'enquadrado_mei' => $this->enquadrado_mei,
            'situation' => $this->situation,
            'count' => $this->count,
            'observed_at' => $this->last_valid_query_at?->toIso8601String(),
            'source_provenance' => $this->source_provenance,
        ];
    }
}
