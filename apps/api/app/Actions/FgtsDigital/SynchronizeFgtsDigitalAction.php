<?php

namespace App\Actions\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalSyncData;
use App\Jobs\Fiscal\ExecuteFgtsDigitalRunJob;
use App\Models\FgtsDigitalRun;
use App\Models\User;
use App\Services\FgtsDigital\Exceptions\FgtsDigitalException;
use App\Services\FgtsDigital\FgtsDigitalPortalService;
use App\Services\FgtsDigital\FgtsDigitalQuery;
use App\Services\FgtsDigital\FgtsDigitalReadinessService;
use App\Support\CurrentTenant;

final readonly class SynchronizeFgtsDigitalAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private FgtsDigitalQuery $query,
        private FgtsDigitalReadinessService $readiness,
        private FgtsDigitalPortalService $portal,
    ) {}

    public function queue(
        User $actor,
        FgtsDigitalSyncData $data,
    ): FgtsDigitalRun {
        $tenant = $this->currentTenant->tenant();
        $client = $this->query->client($data->clientId);
        $readiness = $this->readiness->check($tenant, $client);
        if (! $readiness->readyForRead) {
            throw FgtsDigitalException::readinessBlocked($readiness);
        }

        $run = $this->portal->createQueryRun(
            $tenant,
            $client,
            $actor,
            $data->parameters,
        );
        ExecuteFgtsDigitalRunJob::dispatch(
            (int) $tenant->id,
            (int) $run->id,
        )->afterCommit();

        return $run;
    }

    public function execute(
        User $actor,
        FgtsDigitalSyncData $data,
    ): FgtsDigitalRun {
        $tenant = $this->currentTenant->tenant();
        $client = $this->query->client($data->clientId);
        $readiness = $this->readiness->check($tenant, $client);
        if (! $readiness->readyForRead) {
            throw FgtsDigitalException::readinessBlocked($readiness);
        }

        $run = $this->portal->createQueryRun(
            $tenant,
            $client,
            $actor,
            $data->parameters,
        );

        return $this->portal->executeRun($run);
    }
}
