<?php

namespace App\Models;

use App\Enums\FiscalPaymentStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'declaration_id',
    'competence_id',
    'evidence_version_id',
    'evidence_artifact_id',
    'document_number',
    'amount',
    'due_at',
    'issued_at',
    'payment_status',
    'content_sha256',
    'metadata',
])]
class DctfwebDarfDocument extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'payment_status' => FiscalPaymentStatus::class,
            'metadata' => 'array',
        ];
    }

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(DctfwebDeclaration::class, 'declaration_id');
    }

    public function evidenceVersion(): BelongsTo
    {
        return $this->belongsTo(DctfwebEvidenceVersion::class, 'evidence_version_id');
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
            'declaration_id' => $this->declaration_id,
            'competence_id' => $this->competence_id,
            'evidence_version_id' => $this->evidence_version_id,
            'evidence_artifact_id' => $this->evidence_artifact_id,
            'document_number' => $this->document_number,
            'amount' => $this->amount,
            'due_at' => $this->due_at?->toIso8601String(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'payment_status' => $this->payment_status?->value,
            'content_sha256' => $this->content_sha256,
        ];
    }
}
