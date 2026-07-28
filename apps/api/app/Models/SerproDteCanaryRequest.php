<?php

namespace App\Models;

use App\Enums\SerproDteCanaryRequestStatus;
use App\Enums\SerproEnvironment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Canário DTE: orquestrado pela plataforma com tenant_id de piloto.
 * Acesso dual (Platform/* + tenant result/confirm) — sem BelongsToTenant;
 * isolamento explícito nos services (DteCanaryTenantService / SerproDteCanaryService).
 */
#[Fillable([
    'environment',
    'status',
    'tenant_id',
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
    'tenant_admin_approver_user_id',
    'tenant_admin_approved_at',
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
            'tenant_admin_approved_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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

    public function hasTenantAdminApproval(): bool
    {
        return $this->tenant_admin_approver_user_id !== null && $this->tenant_admin_approved_at !== null;
    }

    public function isFullyApproved(): bool
    {
        if (! $this->hasOwnerApproval() || ! $this->hasTenantAdminApproval()) {
            return false;
        }

        return (int) $this->owner_approver_user_id !== (int) $this->tenant_admin_approver_user_id;
    }
}
