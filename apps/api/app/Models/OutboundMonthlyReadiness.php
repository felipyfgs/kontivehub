<?php

namespace App\Models;

use App\Enums\OutboundMonthlyReadinessStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'competence', 'status', 'known_total', 'captured_total', 'pending_total',
    'export_id', 'manifest_vault_object_id', 'confirmed_by', 'confirmed_at',
    'confirmation_notes', 'summary',
])]
#[Hidden(['manifest_vault_object_id'])]
class OutboundMonthlyReadiness extends Model
{
    use BelongsToOffice;

    protected $table = 'outbound_monthly_readiness';

    protected function casts(): array
    {
        return [
            'status' => OutboundMonthlyReadinessStatus::class,
            'known_total' => 'integer',
            'captured_total' => 'integer',
            'pending_total' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'summary' => 'array',
        ];
    }

    public function export(): BelongsTo
    {
        return $this->belongsTo(Export::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'competence' => $this->competence,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'known_total' => $this->known_total,
            'captured_total' => $this->captured_total,
            'pending_total' => $this->pending_total,
            'export_id' => $this->export_id,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'summary' => $this->summary,
            // Nunca afirmar universo fiscal completo
            'completeness_scope' => 'known_documents_only',
        ];
    }
}
