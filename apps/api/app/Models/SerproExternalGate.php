<?php

namespace App\Models;

use App\Enums\SerproExternalGateKind;
use App\Enums\SerproExternalGateStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'kind',
    'environment',
    'status',
    'title',
    'description',
    'ticket_ref',
    'evidence_ref',
    'submitted_at',
    'answered_at',
    'accepted_at',
    'answer_summary',
    'responsible_name',
    'reference_date',
    'updated_by_user_id',
    'metadata',
])]
class SerproExternalGate extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => SerproExternalGateKind::class,
            'status' => SerproExternalGateStatus::class,
            'submitted_at' => 'immutable_datetime',
            'answered_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'reference_date' => 'immutable_date',
            'metadata' => 'array',
        ];
    }

    public function blocksProduction(): bool
    {
        return $this->status->blocksProduction();
    }

    /**
     * Aceite completo exige referência, resumo, responsável e data (sem waiver).
     */
    public function hasCompleteAcceptanceFields(): bool
    {
        return filled($this->ticket_ref)
            && filled($this->answer_summary)
            && filled($this->responsible_name)
            && $this->reference_date !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'label' => $this->kind->label(),
            'environment' => $this->environment ?? 'PRODUCTION',
            'status' => $this->status->value,
            'title' => $this->title,
            'description' => $this->description,
            'ticket_ref' => $this->ticket_ref,
            'evidence_ref' => $this->evidence_ref,
            'responsible_name' => $this->responsible_name,
            'reference_date' => $this->reference_date?->toDateString(),
            'blocks_production' => $this->blocksProduction(),
            'is_complete' => $this->hasCompleteAcceptanceFields()
                && $this->status === SerproExternalGateStatus::Accepted,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'answered_at' => $this->answered_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'answer_summary' => $this->answer_summary,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
