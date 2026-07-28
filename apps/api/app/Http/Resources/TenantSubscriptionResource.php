<?php

namespace App\Http\Resources;

use App\Models\TenantSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantSubscription */
final class TenantSubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'plan' => $this->plan->value,
            'status' => $this->status->value,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'current_period_starts_at' => $this->current_period_starts_at?->toIso8601String(),
            'current_period_ends_at' => $this->current_period_ends_at?->toIso8601String(),
            'limits' => [
                'monthly_api_quota' => $this->monthly_api_quota,
                'max_clients' => $this->max_clients,
                'max_users' => $this->max_users,
                'commercial_monitor_units' => $this->resolvedCommercialMonitorUnits(),
                'commercial_max_clients' => $this->effectiveCommercialMaxClients(),
            ],
            'allows_mutations' => $this->allowsMutations(),
            'allows_external_calls' => $this->allowsExternalCalls(),
        ];
    }
}
