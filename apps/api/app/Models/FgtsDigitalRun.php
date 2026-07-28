<?php

namespace App\Models;

use App\Enums\FgtsDigitalGuideType;
use App\Enums\FgtsDigitalOperation;
use App\Enums\FgtsDigitalRunStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id', 'client_id', 'requested_by', 'session_id', 'fiscal_mutation_operation_id', 'tax_guide_id', 'tax_guide_version_id',
    'operation', 'guide_type', 'status', 'code', 'idempotency_key', 'request_digest',
    'request_vault_object_id', 'preview_token_hash', 'confirmation_phrase', 'preview_expires_at', 'request_sanitized',
    'result_sanitized', 'correlation_id', 'started_at', 'finished_at',
])]
#[Hidden(['request_vault_object_id', 'preview_token_hash', 'request_digest'])]
class FgtsDigitalRun extends Model
{
    use BelongsToTenant;

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
}
