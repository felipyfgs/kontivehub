<?php

namespace App\Models;

use App\Casts\FiscalSourceProvenanceCast;
use App\Enums\FiscalVerificationKind;
use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Models\Concerns\BelongsToOffice;
use App\Services\MeiAutomation\MeiAutomationMetadataSanitizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'fiscal_monitoring_run_id',
    'fiscal_mutation_operation_id',
    'external_job_id',
    'operation_key',
    'provider',
    'status',
    'idempotency_key',
    'request_fingerprint',
    'attempt_number',
    'source_provenance',
    'verification_kind',
    'portal_version',
    'parser_version',
    'captcha_driver',
    'captcha_cost_micros',
    'fallback_reason',
    'error_code',
    'error_message',
    'safe_metadata',
    'vault_artifacts',
    'result_payload_encrypted',
    'started_at',
    'last_synced_at',
    'submitted_at',
    'sync_lost_at',
    'finished_at',
])]
#[Hidden([
    'external_job_id',
    'idempotency_key',
    'request_fingerprint',
    'portal_version',
    'parser_version',
    'captcha_driver',
    'captcha_cost_micros',
    'safe_metadata',
    'vault_artifacts',
    'result_payload_encrypted',
])]
class MeiAutomationAttempt extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'provider' => MeiProvider::class,
            'status' => MeiAutomationStatus::class,
            'source_provenance' => FiscalSourceProvenanceCast::class,
            'verification_kind' => FiscalVerificationKind::class,
            'attempt_number' => 'integer',
            'captcha_cost_micros' => 'integer',
            'safe_metadata' => 'array',
            'vault_artifacts' => 'array',
            'result_payload_encrypted' => 'encrypted:array',
            'started_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'sync_lost_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function monitoringRun(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'fiscal_monitoring_run_id');
    }

    public function mutationOperation(): BelongsTo
    {
        return $this->belongsTo(FiscalMutationOperation::class, 'fiscal_mutation_operation_id');
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'operation_key' => $this->operation_key,
            'provider' => $this->provider?->value,
            'status' => $this->status?->value,
            'source_provenance' => $this->source_provenance instanceof \BackedEnum
                ? $this->source_provenance->value
                : $this->source_provenance,
            'verification_kind' => $this->verification_kind?->value,
            'fallback_reason' => $this->fallback_reason,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'metadata' => app(MeiAutomationMetadataSanitizer::class)->sanitize($this->safe_metadata ?? []),
            'artifacts' => collect($this->vault_artifacts ?? [])
                ->filter(static fn (mixed $artifact): bool => is_array($artifact))
                ->map(static fn (array $artifact): array => [
                    'id' => $artifact['id'] ?? null,
                    'name' => $artifact['name'] ?? null,
                    'content_type' => $artifact['content_type'] ?? null,
                    'byte_size' => $artifact['byte_size'] ?? null,
                    'sha256' => $artifact['sha256'] ?? null,
                ])->values()->all(),
            'started_at' => $this->started_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
