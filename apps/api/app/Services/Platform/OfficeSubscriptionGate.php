<?php

namespace App\Services\Platform;

use App\Models\Office;
use App\Models\OfficeSubscription;
use App\Support\CurrentOffice;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gate operacional: SUSPENDED/CANCELED bloqueiam mutações e chamadas externas;
 * leitura de histórico/evidência permanece permitida.
 */
final class OfficeSubscriptionGate
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
    ) {}

    public function subscriptionFor(?Office $office = null): ?OfficeSubscription
    {
        $office = $office ?? $this->currentOffice->resolve();

        if ($office === null) {
            return null;
        }

        return OfficeSubscription::query()
            ->where('office_id', $office->id)
            ->first();
    }

    public function allowsMutations(?Office $office = null): bool
    {
        $subscription = $this->subscriptionFor($office);

        // Sem registro: fail-closed para mutações (migração deve ter seeded ACTIVE).
        if ($subscription === null) {
            return false;
        }

        return $subscription->allowsMutations();
    }

    public function allowsExternalCalls(?Office $office = null): bool
    {
        $subscription = $this->subscriptionFor($office);

        if ($subscription === null) {
            return false;
        }

        return $subscription->allowsExternalCalls();
    }

    public function allowsRead(?Office $office = null): bool
    {
        $subscription = $this->subscriptionFor($office);

        // Sem assinatura ainda permite leitura de histórico se membership válida.
        if ($subscription === null) {
            return true;
        }

        return $subscription->allowsRead();
    }

    /**
     * @throws HttpException 403
     */
    public function assertAllowsMutations(?Office $office = null): void
    {
        if (! $this->allowsMutations($office)) {
            $status = $this->subscriptionFor($office)?->status?->value ?? 'MISSING';

            abort(403, "Escritório com assinatura {$status}: mutações bloqueadas.");
        }
    }

    /**
     * @throws HttpException 403
     */
    public function assertAllowsExternalCalls(?Office $office = null): void
    {
        if (! $this->allowsExternalCalls($office)) {
            $status = $this->subscriptionFor($office)?->status?->value ?? 'MISSING';

            abort(403, "Escritório com assinatura {$status}: chamadas externas bloqueadas.");
        }
    }
}
