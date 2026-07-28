<?php

namespace App\Actions\Fiscal\Mutations;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\DTO\Fiscal\Mutations\RefreshSitfisSituationData;
use App\Models\User;
use App\Services\Integra\Sitfis\SitfisSnapshotService;
use App\Support\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class RefreshSitfisSituationAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private FindFiscalClientAction $findClient,
        private SitfisSnapshotService $sitfis,
    ) {}

    /**
     * @return array{enqueued: bool, reused_snapshot: mixed, reason: mixed, run: mixed, view: mixed}
     */
    public function handle(?User $actor, RefreshSitfisSituationData $data): array
    {
        $tenant = $this->currentTenant->tenant();
        $client = $this->findClient->handle($tenant, $data->clientId);
        if ($client === null) {
            throw new NotFoundHttpException('Cliente não encontrado.');
        }

        return $this->sitfis->refresh(
            tenant: $tenant,
            client: $client,
            force: $data->force,
            actorId: $actor?->id,
            dispatch: true,
        );
    }
}
