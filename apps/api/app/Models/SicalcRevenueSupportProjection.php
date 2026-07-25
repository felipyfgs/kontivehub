<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['office_id', 'client_id', 'revenue_code', 'description', 'extensions', 'extension_count', 'last_valid_query_at', 'last_observation_id', 'last_run_id', 'source_provenance'])]
class SicalcRevenueSupportProjection extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return ['extensions' => 'array', 'extension_count' => 'integer', 'last_valid_query_at' => 'immutable_datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lastObservation(): BelongsTo
    {
        return $this->belongsTo(SicalcRevenueSupportObservation::class, 'last_observation_id');
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'revenue_code' => $this->revenue_code,
            'description' => $this->description,
            'extensions' => $this->extensions,
            'extension_count' => $this->extension_count,
            'observed_at' => $this->last_valid_query_at?->toIso8601String(),
            'source_provenance' => $this->source_provenance,
        ];
    }
}
