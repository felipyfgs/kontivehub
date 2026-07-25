<?php

namespace App\Models;

use App\Enums\FgtsDigitalGuideType;
use App\Enums\FgtsDigitalOperation;
use App\Enums\FgtsDigitalRunStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'office_id', 'client_id', 'requested_by', 'session_id', 'fiscal_mutation_operation_id', 'tax_guide_id', 'tax_guide_version_id',
    'operation', 'guide_type', 'status', 'code', 'idempotency_key', 'request_digest',
    'request_vault_object_id', 'preview_token_hash', 'confirmation_phrase', 'preview_expires_at', 'request_sanitized',
    'result_sanitized', 'correlation_id', 'started_at', 'finished_at',
])]
#[Hidden(['request_vault_object_id', 'preview_token_hash', 'request_digest'])]
class FgtsDigitalRun extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'operation' => FgtsDigitalOperation::class,
            'guide_type' => FgtsDigitalGuideType::class,
            'status' => FgtsDigitalRunStatus::class,
            'preview_expires_at' => 'immutable_datetime',
            'request_sanitized' => 'array',
            'result_sanitized' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'operation' => $this->operation->value,
            'guide_type' => $this->guide_type?->value,
            'status' => $this->status->value,
            'code' => $this->code,
            'confirmation_phrase' => $this->confirmation_phrase,
            'preview_expires_at' => $this->preview_expires_at?->toIso8601String(),
            'request' => $this->request_sanitized,
            'result' => $this->result_sanitized,
            'tax_guide_id' => $this->tax_guide_id,
            'tax_guide_version_id' => $this->tax_guide_version_id,
            'fiscal_mutation_operation_id' => $this->fiscal_mutation_operation_id,
            'correlation_id' => $this->correlation_id,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
