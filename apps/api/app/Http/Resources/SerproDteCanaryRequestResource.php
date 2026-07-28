<?php

namespace App\Http\Resources;

use App\Enums\SerproDteCanaryRequestStatus;
use App\Enums\SerproEnvironment;
use App\Models\SerproDteCanaryRequest;
use App\Support\Serpro\DteCanaryCoordinates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproDteCanaryRequest */
final class SerproDteCanaryRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SerproDteCanaryRequest $canary */
        $canary = $this->resource;

        return [
            'id' => $canary->id,
            'environment' => $canary->environment instanceof SerproEnvironment
                ? $canary->environment->value
                : (string) $canary->environment,
            'status' => $canary->status instanceof SerproDteCanaryRequestStatus
                ? $canary->status->value
                : (string) $canary->status,
            'tenant_id' => $canary->tenant_id,
            'client_id' => $canary->client_id,
            'operation_key' => $canary->operation_key ?? DteCanaryCoordinates::OPERATION_KEY,
            'id_sistema' => $canary->id_sistema ?? DteCanaryCoordinates::ID_SISTEMA,
            'id_servico' => $canary->id_servico ?? DteCanaryCoordinates::ID_SERVICO,
            'service_version' => $canary->service_version ?? DteCanaryCoordinates::SERVICE_VERSION,
            'functional_route' => $canary->functional_route ?? DteCanaryCoordinates::FUNCTIONAL_ROUTE,
            'required_proxy_power' => $canary->required_proxy_power ?? DteCanaryCoordinates::REQUIRED_PROXY_POWER,
            'owner_approved' => $canary->hasOwnerApproval(),
            'tenant_admin_approved' => $canary->hasTenantAdminApproval(),
            'fully_approved' => $canary->isFullyApproved(),
            'owner_approver_user_id' => $canary->owner_approver_user_id,
            'tenant_admin_approver_user_id' => $canary->tenant_admin_approver_user_id,
            'owner_approved_at' => $canary->owner_approved_at?->toIso8601String(),
            'tenant_admin_approved_at' => $canary->tenant_admin_approved_at?->toIso8601String(),
            'idempotency_key' => $canary->idempotency_key,
            'correlation_id' => $canary->correlation_id,
            'request_tag' => $canary->request_tag,
            'attempt_id' => $canary->attempt_id,
            'consumption_quantity' => (int) $canary->consumption_quantity,
            'result_status' => $canary->result_status,
            'dispatched_at' => $canary->dispatched_at?->toIso8601String(),
            'finished_at' => $canary->finished_at?->toIso8601String(),
            'reconciled' => $canary->reconciled_at !== null,
            'reconciliation_reference' => $canary->reconciliation_reference,
            'reconciliation_summary' => $canary->reconciliation_summary,
            'reconciled_at' => $canary->reconciled_at?->toIso8601String(),
            'selected_at' => $canary->selected_at?->toIso8601String(),
            'expires_at' => $canary->expires_at?->toIso8601String(),
            'created_at' => $canary->created_at?->toIso8601String(),
            'updated_at' => $canary->updated_at?->toIso8601String(),
        ];
    }
}
