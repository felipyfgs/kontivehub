<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Esocial\SynchronizeFgtsEsocialAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\FgtsEsocial\ExecuteFgtsEsocialSyncRequest;
use App\Http\Requests\FgtsEsocial\ListFgtsEsocialCompetencesRequest;
use App\Http\Requests\FgtsEsocial\ListFgtsEsocialEventsRequest;
use App\Http\Requests\FgtsEsocial\QueueFgtsEsocialSyncRequest;
use App\Http\Requests\FgtsEsocial\ShowFgtsEsocialCompetenceRequest;
use App\Http\Requests\FgtsEsocial\ShowFgtsEsocialCoverageRequest;
use App\Http\Requests\FgtsEsocial\ShowFgtsEsocialReadinessRequest;
use App\Http\Resources\FgtsEsocial\FgtsEsocialCompetenceDetailResource;
use App\Http\Resources\FgtsEsocial\FgtsEsocialCompetencePageResource;
use App\Http\Resources\FgtsEsocial\FgtsEsocialCoverageResource;
use App\Http\Resources\FgtsEsocial\FgtsEsocialEventPageResource;
use App\Http\Resources\FgtsEsocial\FgtsEsocialQueuedSyncResource;
use App\Http\Resources\FgtsEsocial\FgtsEsocialReadinessResource;
use App\Http\Resources\FgtsEsocial\FgtsEsocialSyncResource;
use App\Services\Esocial\FgtsEsocialQuery;
use Illuminate\Http\JsonResponse;

/**
 * API tenant-scoped do monitoramento parcial FGTS via eSocial.
 * Respostas sempre incluem limitações de cobertura e não expõem débito do portal.
 */
final class FgtsEsocialController extends Controller
{
    public function __construct(
        private readonly FgtsEsocialQuery $query,
        private readonly SynchronizeFgtsEsocialAction $synchronize,
    ) {}

    public function coverage(
        ShowFgtsEsocialCoverageRequest $request,
    ): JsonResponse {
        return (new FgtsEsocialCoverageResource(
            $this->query->coverage(),
        ))->response();
    }

    public function readiness(
        ShowFgtsEsocialReadinessRequest $request,
    ): JsonResponse {
        return (new FgtsEsocialReadinessResource(
            $this->query->readiness($request->clientId()),
        ))->response();
    }

    public function competences(
        ListFgtsEsocialCompetencesRequest $request,
    ): JsonResponse {
        return (new FgtsEsocialCompetencePageResource(
            $this->query->competences($request->filters()),
        ))->response();
    }

    public function showCompetence(
        ShowFgtsEsocialCompetenceRequest $request,
        int $status,
    ): JsonResponse {
        return (new FgtsEsocialCompetenceDetailResource(
            $this->query->competenceDetail($status),
        ))->response();
    }

    public function events(
        ListFgtsEsocialEventsRequest $request,
    ): JsonResponse {
        $resource = new FgtsEsocialEventPageResource(
            $this->query->events($request->filters()),
        );

        return $resource
            ->withCoverage($this->query->coverage())
            ->response();
    }

    public function sync(
        QueueFgtsEsocialSyncRequest $request,
    ): JsonResponse {
        return (new FgtsEsocialQueuedSyncResource(
            $this->synchronize->queue(
                $request->actor(),
                $request->syncData(),
            ),
        ))->response()->setStatusCode(202);
    }

    public function syncNow(
        ExecuteFgtsEsocialSyncRequest $request,
    ): JsonResponse {
        return (new FgtsEsocialSyncResource(
            $this->synchronize->execute($request->syncData()),
        ))->response();
    }
}
