<?php

namespace App\Http\Resources;

use App\Enums\TenantLifecycleStatus;
use App\Models\AccountActivation;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
final class PlatformTenantAdminSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->resource;

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'is_active' => $tenant->is_active,
            'lifecycle_status' => $tenant->lifecycle_status instanceof TenantLifecycleStatus
                ? $tenant->lifecycle_status->value
                : (string) ($tenant->lifecycle_status ?? TenantLifecycleStatus::Active->value),
            'subscription' => $this->subscription($tenant->subscription),
            'activation' => $this->activation($tenant->latestFirstAdminActivation),
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function subscription(?TenantSubscription $subscription): ?array
    {
        if ($subscription === null) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'plan' => $subscription->plan->value,
            'status' => $subscription->status->value,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'starts_at' => $subscription->starts_at?->toIso8601String(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            'current_period_starts_at' => $subscription->current_period_starts_at?->toIso8601String(),
            'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
            'limits' => [
                'monthly_api_quota' => $subscription->monthly_api_quota,
                'max_clients' => $subscription->max_clients,
                'max_users' => $subscription->max_users,
                'commercial_monitor_units' => $subscription->resolvedCommercialMonitorUnits(),
                'commercial_max_clients' => $subscription->effectiveCommercialMaxClients(),
            ],
            'allows_mutations' => $subscription->allowsMutations(),
            'allows_external_calls' => $subscription->allowsExternalCalls(),
            'notes' => $subscription->notes,
            'negotiated_client_limit' => $subscription->negotiated_client_limit,
            'updated_at' => $subscription->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function activation(?AccountActivation $activation): ?array
    {
        if ($activation === null) {
            return null;
        }

        return [
            'id' => $activation->id,
            'purpose' => $activation->purpose->value,
            'method' => $activation->method->value,
            'status' => $activation->publicStatus(),
            'expires_at' => $activation->expires_at?->toIso8601String(),
            'consumed_at' => $activation->consumed_at?->toIso8601String(),
            'revoked_at' => $activation->revoked_at?->toIso8601String(),
            'generation' => $activation->generation,
            'email_masked' => AccountActivation::maskEmail($activation->email_normalized),
        ];
    }
}
