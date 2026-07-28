<?php

namespace App\Actions\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\MonitoringMembershipData;
use App\Models\User;
use App\Services\FiscalMonitoring\MonitoringModuleMembershipService;
use App\Support\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final readonly class ManageMonitoringMembershipAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private MonitoringModuleMembershipService $membership,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function exclude(?User $actor, MonitoringMembershipData $data): array
    {
        if (! $data->isValidModule()) {
            throw new UnprocessableEntityHttpException('Módulo inválido.');
        }

        return $this->membership->exclude(
            $this->currentTenant->tenant(),
            $data->module,
            $data->clientIds,
            $data->submodule,
            $actor?->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function include(MonitoringMembershipData $data): array
    {
        if (! $data->isValidModule()) {
            throw new UnprocessableEntityHttpException('Módulo inválido.');
        }

        return $this->membership->include(
            $this->currentTenant->tenant(),
            $data->module,
            $data->clientIds,
            $data->submodule,
        );
    }
}
