<?php

namespace App\Models;

use App\Enums\SerproReadinessGate;
use App\Enums\SerproReadinessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'serpro_readiness_run_id',
    'gate',
    'scope',
    'status',
    'live_evidence',
    'fingerprint',
    'document_revision',
    'sanitized_reason',
    'observed_at',
    'valid_until',
    'metadata',
])]
class SerproReadinessEvidence extends Model
{
    protected $table = 'serpro_readiness_evidences';

    protected function casts(): array
    {
        return [
            'gate' => SerproReadinessGate::class,
            'scope' => SerproReadinessScope::class,
            'live_evidence' => 'boolean',
            'observed_at' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SerproReadinessRun::class, 'serpro_readiness_run_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'gate' => $this->gate->value,
            'scope' => $this->scope->value,
            'status' => $this->status,
            'live_evidence' => $this->live_evidence,
            'fingerprint' => $this->fingerprint,
            'document_revision' => $this->document_revision,
            'sanitized_reason' => $this->sanitized_reason,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'valid_until' => $this->valid_until?->toIso8601String(),
        ];
    }
}
