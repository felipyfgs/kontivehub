<?php

namespace App\Actions\Tenant;

use App\Models\TenantSubscription;
use App\Support\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ShowSubscriptionAction
{
    public function __construct(private CurrentTenant $currentTenant) {}

    public function __invoke(): TenantSubscription
    {
        return TenantSubscription::query()
            ->where(
                'tenant_id',
                (int) $this->currentTenant->tenant()->id,
            )
            ->first()
            ?? throw new NotFoundHttpException(
                'Assinatura não encontrada para o escritório atual.',
            );
    }
}
