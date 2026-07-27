<?php

namespace App\Services\Platform;

use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Support\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gate operacional: SUSPENDED/CANCELED bloqueiam mutações e chamadas externas;
 * leitura de histórico/evidência permanece permitida.
 */
final class TenantSubscriptionGate
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function subscriptionFor(?Tenant $tenant = null): ?TenantSubscription
    {
        $tenant = $tenant ?? $this->currentTenant->resolve();

        if ($tenant === null) {
            return null;
        }

        return TenantSubscription::query()
            ->where('tenant_id', $tenant->id)
            ->first();
    }

    public function allowsMutations(?Tenant $tenant = null): bool
    {
        $subscription = $this->subscriptionFor($tenant);

        // Sem registro: fail-closed para mutações (migração deve ter seeded ACTIVE).
        if ($subscription === null) {
            return false;
        }

        return $subscription->allowsMutations();
    }

    public function allowsExternalCalls(?Tenant $tenant = null): bool
    {
        $subscription = $this->subscriptionFor($tenant);

        if ($subscription === null) {
            return false;
        }

        return $subscription->allowsExternalCalls();
    }

    public function allowsRead(?Tenant $tenant = null): bool
    {
        $subscription = $this->subscriptionFor($tenant);

        // Sem assinatura ainda permite leitura de histórico se membership válida.
        if ($subscription === null) {
            return true;
        }

        return $subscription->allowsRead();
    }

    /**
     * @throws HttpException 403
     */
    public function assertAllowsMutations(?Tenant $tenant = null): void
    {
        if (! $this->allowsMutations($tenant)) {
            $status = $this->subscriptionFor($tenant)?->status?->value ?? 'MISSING';

            abort(403, "Escritório com assinatura {$status}: mutações bloqueadas.");
        }
    }

    /**
     * @throws HttpException 403
     */
    public function assertAllowsExternalCalls(?Tenant $tenant = null): void
    {
        if (! $this->allowsExternalCalls($tenant)) {
            $status = $this->subscriptionFor($tenant)?->status?->value ?? 'MISSING';

            abort(403, "Escritório com assinatura {$status}: chamadas externas bloqueadas.");
        }
    }
}
