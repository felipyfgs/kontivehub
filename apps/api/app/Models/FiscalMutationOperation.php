<?php

namespace App\Models;

use App\Enums\FiscalMutationStatus;
use App\Enums\SerproEnvironment;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'client_id',
    'requested_by',
    'idempotency_key',
    'logical_key',
    'correlation_id',
    'preflight_token',
    'environment',
    'solution_code',
    'service_code',
    'operation_code',
    'operation_key',
    'module_key',
    'competence_period_key',
    'status',
    'effect_summary',
    'confirmation_phrase',
    'confirmation_required',
    'confirmed_by_user',
    'confirmed_at',
    'request_sanitized',
    'request_payload_encrypted',
    'request_payload_digest',
    'pre_operation_snapshot',
    'eligibility_snapshot',
    'cost_estimate',
    'estimated_cost_micros',
    'result_code',
    'result_message',
    'result_sanitized',
    'evidence_ref',
    'external_correlation',
    'attempt_count',
    'reconcile_count',
    'preflight_at',
    'preflight_expires_at',
    'sent_at',
    'terminal_at',
    'last_reconcile_at',
    'latency_ms',
    'simulated',
    'denial_code',
    'denial_message',
])]
#[Hidden([
    'preflight_token',
    'request_sanitized',
    'request_payload_encrypted',
    'request_payload_digest',
])]
class FiscalMutationOperation extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'environment' => SerproEnvironment::class,
            'status' => FiscalMutationStatus::class,
            'confirmation_required' => 'boolean',
            'confirmed_by_user' => 'boolean',
            'confirmed_at' => 'immutable_datetime',
            'request_sanitized' => 'array',
            'request_payload_encrypted' => 'encrypted:array',
            'pre_operation_snapshot' => 'array',
            'eligibility_snapshot' => 'array',
            'cost_estimate' => 'array',
            'result_sanitized' => 'array',
            'estimated_cost_micros' => 'integer',
            'attempt_count' => 'integer',
            'reconcile_count' => 'integer',
            'preflight_at' => 'immutable_datetime',
            'preflight_expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'terminal_at' => 'immutable_datetime',
            'last_reconcile_at' => 'immutable_datetime',
            'latency_ms' => 'integer',
            'simulated' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FiscalMutationOperationEvent::class);
    }

    public function isPreflightValid(): bool
    {
        if ($this->preflight_expires_at === null) {
            return false;
        }

        return $this->preflight_expires_at->isFuture();
    }

    public function blocksNewMutation(): bool
    {
        return $this->status->isUncertain() || $this->status === FiscalMutationStatus::Pending;
    }

    /**
     * Resposta pública — sem payload fiscal, segredo ou CNPJ completo.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'client_id' => $this->client_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'environment' => $this->environment?->value,
            'solution_code' => $this->solution_code,
            'service_code' => $this->service_code,
            'operation_code' => $this->operation_code,
            'operation_key' => $this->operation_key,
            'module_key' => $this->module_key,
            'competence_period_key' => $this->competence_period_key,
            'effect_summary' => $this->effect_summary,
            'confirmation_required' => $this->confirmation_required,
            'confirmation_phrase' => $this->confirmation_phrase,
            'confirmed_by_user' => $this->confirmed_by_user,
            'idempotency_key' => $this->idempotency_key,
            'correlation_id' => $this->correlation_id,
            'preflight_expires_at' => $this->preflight_expires_at?->toIso8601String(),
            'cost_estimate' => $this->cost_estimate,
            'estimated_cost_micros' => $this->estimated_cost_micros,
            'eligibility' => $this->eligibility_snapshot,
            'pre_operation_snapshot' => $this->pre_operation_snapshot,
            'result_code' => $this->result_code,
            'result_message' => $this->result_message,
            'result_sanitized' => $this->result_sanitized,
            'evidence_ref' => $this->evidence_ref,
            'external_correlation' => $this->external_correlation,
            'attempt_count' => $this->attempt_count,
            'reconcile_count' => $this->reconcile_count,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'terminal_at' => $this->terminal_at?->toIso8601String(),
            'last_reconcile_at' => $this->last_reconcile_at?->toIso8601String(),
            'latency_ms' => $this->latency_ms,
            'simulated' => $this->simulated,
            'denial_code' => $this->denial_code,
            'denial_message' => $this->denial_message,
            'is_terminal' => $this->status->isTerminal(),
            'is_uncertain' => $this->status->isUncertain(),
            'allows_reconciliation' => $this->status->allowsReconciliation(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
