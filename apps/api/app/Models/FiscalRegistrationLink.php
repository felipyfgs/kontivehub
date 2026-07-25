<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'contributor_cnpj',
    'link_key',
    'status',
    'evidence_version',
    'operation_key',
    'source_provenance',
    'is_simulated',
    'summary_sanitized',
    'observed_at',
    'refreshed_at',
])]
class FiscalRegistrationLink extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'is_simulated' => 'boolean',
            'summary_sanitized' => 'array',
            'observed_at' => 'immutable_datetime',
            'refreshed_at' => 'immutable_datetime',
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
            'client_id' => $this->client_id,
            'contributor_ref' => substr(hash('sha256', (string) $this->contributor_cnpj), 0, 12),
            'link_key' => $this->link_key,
            'status' => $this->status,
            'evidence_version' => $this->evidence_version,
            'operation_key' => $this->operation_key,
            'source_provenance' => $this->source_provenance,
            'is_simulated' => $this->is_simulated,
            'summary' => $this->summary_sanitized,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'refreshed_at' => $this->refreshed_at?->toIso8601String(),
        ];
    }
}
