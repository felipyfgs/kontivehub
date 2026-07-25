<?php

namespace App\Models;

use App\Enums\SerproDteCanaryRequestStatus;
use App\Enums\SerproEnvironment;
use App\Support\Serpro\DteCanaryCoordinates;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Canário DTE: orquestrado pela plataforma com office_id de piloto.
 * Acesso dual (Platform/* + tenant result/confirm) — sem BelongsToOffice;
 * isolamento explícito nos services (DteCanaryTenantService / SerproDteCanaryService).
 *
 * @see config/schema_conventions.php belongs_to_office_exceptions
 */
#[Fillable([
    'environment',
    'status',
    'office_id',
    'client_id',
    'selected_by_user_id',
    'selected_at',
    'operation_key',
    'id_sistema',
    'id_servico',
    'service_version',
    'functional_route',
    'required_proxy_power',
    'owner_approver_user_id',
    'owner_approved_at',
    'office_admin_approver_user_id',
    'office_admin_approved_at',
    'idempotency_key',
    'correlation_id',
    'request_tag',
    'attempt_id',
    'consumption_quantity',
    'result_status',
    'dispatched_at',
    'finished_at',
    'reconciliation_reference',
    'reconciliation_summary',
    'reconciled_by_user_id',
    'reconciled_at',
    'created_by_user_id',
    'expires_at',
    'metadata',
])]
class SerproDteCanaryRequest extends Model
{
    protected function casts(): array
    {
        return [
            'environment' => SerproEnvironment::class,
            'status' => SerproDteCanaryRequestStatus::class,
            'selected_at' => 'immutable_datetime',
            'owner_approved_at' => 'immutable_datetime',
            'office_admin_approved_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(SerproOperationAttempt::class, 'attempt_id');
    }

    public function hasOwnerApproval(): bool
    {
        return $this->owner_approver_user_id !== null && $this->owner_approved_at !== null;
    }

    public function hasOfficeAdminApproval(): bool
    {
        return $this->office_admin_approver_user_id !== null && $this->office_admin_approved_at !== null;
    }

    public function isFullyApproved(): bool
    {
        if (! $this->hasOwnerApproval() || ! $this->hasOfficeAdminApproval()) {
            return false;
        }

        return (int) $this->owner_approver_user_id !== (int) $this->office_admin_approver_user_id;
    }

    /**
     * Resumo global sanitizado — sem payload fiscal, XML ou dados do contribuinte.
     *
     * @return array<string, mixed>
     */
    public function toGlobalSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'environment' => $this->environment instanceof SerproEnvironment
                ? $this->environment->value
                : (string) $this->environment,
            'status' => $this->status instanceof SerproDteCanaryRequestStatus
                ? $this->status->value
                : (string) $this->status,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'operation_key' => $this->operation_key ?? DteCanaryCoordinates::OPERATION_KEY,
            'id_sistema' => $this->id_sistema ?? DteCanaryCoordinates::ID_SISTEMA,
            'id_servico' => $this->id_servico ?? DteCanaryCoordinates::ID_SERVICO,
            'service_version' => $this->service_version ?? DteCanaryCoordinates::SERVICE_VERSION,
            'functional_route' => $this->functional_route ?? DteCanaryCoordinates::FUNCTIONAL_ROUTE,
            'required_proxy_power' => $this->required_proxy_power ?? DteCanaryCoordinates::REQUIRED_PROXY_POWER,
            'owner_approved' => $this->hasOwnerApproval(),
            'office_admin_approved' => $this->hasOfficeAdminApproval(),
            'fully_approved' => $this->isFullyApproved(),
            'owner_approver_user_id' => $this->owner_approver_user_id,
            'office_admin_approver_user_id' => $this->office_admin_approver_user_id,
            'owner_approved_at' => $this->owner_approved_at?->toIso8601String(),
            'office_admin_approved_at' => $this->office_admin_approved_at?->toIso8601String(),
            'idempotency_key' => $this->idempotency_key,
            'correlation_id' => $this->correlation_id,
            'request_tag' => $this->request_tag,
            'attempt_id' => $this->attempt_id,
            'consumption_quantity' => (int) $this->consumption_quantity,
            'result_status' => $this->result_status,
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'reconciled' => $this->reconciled_at !== null,
            'reconciliation_reference' => $this->reconciliation_reference,
            'reconciliation_summary' => $this->reconciliation_summary,
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'selected_at' => $this->selected_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Resultado tenant — inclui campos fiscais sanitizados do attempt (sem vault secrets).
     *
     * @return array<string, mixed>
     */
    public function toTenantResultArray(): array
    {
        $base = $this->toGlobalSanitizedArray();
        $attempt = $this->relationLoaded('attempt') ? $this->attempt : $this->attempt()->first();

        $base['fiscal_result'] = null;
        if ($attempt instanceof SerproOperationAttempt) {
            $base['fiscal_result'] = [
                'success' => $attempt->success,
                'http_status' => $attempt->http_status,
                'error_code' => $attempt->error_code,
                'error_message' => $attempt->error_message,
                'business_status' => $attempt->business_status,
                'attempt_state' => $attempt->attempt_state?->value ?? (string) $attempt->attempt_state,
                'simulated' => (bool) $attempt->simulated,
                'dados' => $attempt->dados,
                'mensagens' => $attempt->mensagens,
                'finished_at' => $attempt->acknowledged_at?->toIso8601String()
                    ?? $attempt->updated_at?->toIso8601String(),
            ];
        }

        return $base;
    }
}
