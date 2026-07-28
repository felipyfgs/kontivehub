<?php

namespace App\Services\Esocial;

use App\DTO\Esocial\EsocialBxReadiness;
use App\DTO\Esocial\FgtsEsocialCompetenceDetailData;
use App\DTO\Esocial\FgtsEsocialEventFilters;
use App\DTO\Esocial\FgtsEsocialListFilters;
use App\Exceptions\FgtsEsocialApiException;
use App\Models\Client;
use App\Models\EsocialEventEvidence;
use App\Models\Establishment;
use App\Models\FgtsCompetenceStatus;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class FgtsEsocialQuery
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private FgtsEsocialMonitoringService $monitoring,
        private EsocialBxReadinessService $readiness,
    ) {}

    /** @return array<string, mixed> */
    public function coverage(): array
    {
        return $this->monitoring->coverageManifest();
    }

    public function readiness(int $clientId): EsocialBxReadiness
    {
        return $this->readiness->check(
            $this->currentTenant->tenant(),
            $this->client($clientId),
        );
    }

    /** @return LengthAwarePaginator<int, FgtsCompetenceStatus> */
    public function competences(
        FgtsEsocialListFilters $filters,
    ): LengthAwarePaginator {
        return $this->monitoring->paginateStatuses(
            $this->currentTenant->tenant(),
            $filters->perPage,
            $filters->clientId,
            $filters->competencePeriodKey,
        );
    }

    public function competenceDetail(
        int $statusId,
    ): FgtsEsocialCompetenceDetailData {
        $tenant = $this->currentTenant->tenant();
        $status = $this->monitoring->findStatusForTenant($tenant, $statusId);
        if ($status === null) {
            throw FgtsEsocialApiException::competenceNotFound();
        }

        $events = $this->monitoring->paginateEvents(
            $tenant,
            100,
            (int) $status->client_id,
            (string) $status->competence_period_key,
        );

        return new FgtsEsocialCompetenceDetailData(
            status: $status,
            events: $events->getCollection(),
            coverage: $this->coverage(),
        );
    }

    /** @return LengthAwarePaginator<int, EsocialEventEvidence> */
    public function events(
        FgtsEsocialEventFilters $filters,
    ): LengthAwarePaginator {
        return $this->monitoring->paginateEvents(
            $this->currentTenant->tenant(),
            $filters->perPage,
            $filters->clientId,
            $filters->competencePeriodKey,
            $filters->eventCode,
        );
    }

    public function client(int $clientId): Client
    {
        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->currentTenant->id())
            ->whereKey($clientId)
            ->first();

        if ($client === null) {
            throw FgtsEsocialApiException::clientNotFound();
        }

        return $client;
    }

    public function establishment(
        Client $client,
        ?int $establishmentId,
    ): ?Establishment {
        if ($establishmentId === null) {
            return null;
        }

        $establishment = Establishment::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->currentTenant->id())
            ->where('client_id', $client->id)
            ->whereKey($establishmentId)
            ->first();

        if ($establishment === null) {
            throw FgtsEsocialApiException::establishmentNotFound();
        }

        return $establishment;
    }
}
