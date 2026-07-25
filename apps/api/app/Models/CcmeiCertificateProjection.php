<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'status',
    'situation',
    'last_valid_query_at',
    'last_observation_id',
    'last_run_id',
    'source_provenance',
])]
class CcmeiCertificateProjection extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'last_valid_query_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lastObservation(): BelongsTo
    {
        return $this->belongsTo(CcmeiCertificateObservation::class, 'last_observation_id');
    }

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'last_run_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'status' => $this->status,
            'situation' => $this->situation,
            'last_valid_query_at' => $this->last_valid_query_at?->toIso8601String(),
            'source_provenance' => $this->source_provenance,
        ];
    }
}
