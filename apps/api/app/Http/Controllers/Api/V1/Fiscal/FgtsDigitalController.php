<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\FgtsDigital\ManageFgtsDigitalCredentialAction;
use App\Actions\FgtsDigital\ManageFgtsDigitalGuideAction;
use App\Actions\FgtsDigital\SynchronizeFgtsDigitalAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\FgtsDigital\EmitFgtsDigitalGuideRequest;
use App\Http\Requests\FgtsDigital\ExecuteFgtsDigitalSyncRequest;
use App\Http\Requests\FgtsDigital\ImportFgtsDigitalSessionRequest;
use App\Http\Requests\FgtsDigital\ListFgtsDigitalRunsRequest;
use App\Http\Requests\FgtsDigital\PreviewFgtsDigitalGuideRequest;
use App\Http\Requests\FgtsDigital\ShowFgtsDigitalReadinessRequest;
use App\Http\Requests\FgtsDigital\StartFgtsDigitalSyncRequest;
use App\Http\Requests\FgtsDigital\StoreFgtsDigitalRepresentationRequest;
use App\Http\Requests\FgtsDigital\ViewFgtsDigitalRequest;
use App\Http\Resources\FgtsDigital\FgtsDigitalCoverageResource;
use App\Http\Resources\FgtsDigital\FgtsDigitalEmissionResource;
use App\Http\Resources\FgtsDigital\FgtsDigitalPreviewResource;
use App\Http\Resources\FgtsDigital\FgtsDigitalReadinessResource;
use App\Http\Resources\FgtsDigital\FgtsDigitalRepresentationResource;
use App\Http\Resources\FgtsDigital\FgtsDigitalRunPageResource;
use App\Http\Resources\FgtsDigital\FgtsDigitalRunResource;
use App\Http\Resources\FgtsDigital\FgtsDigitalSessionResource;
use App\Services\FgtsDigital\FgtsDigitalQuery;
use Illuminate\Http\JsonResponse;

final class FgtsDigitalController extends Controller
{
    public function __construct(
        private readonly FgtsDigitalQuery $query,
        private readonly SynchronizeFgtsDigitalAction $synchronize,
        private readonly ManageFgtsDigitalGuideAction $guides,
        private readonly ManageFgtsDigitalCredentialAction $credentials,
    ) {}

    public function coverage(
        ViewFgtsDigitalRequest $request,
    ): JsonResponse {
        return (new FgtsDigitalCoverageResource(
            $this->query->coverage(),
        ))->response();
    }

    public function readiness(
        ShowFgtsDigitalReadinessRequest $request,
    ): JsonResponse {
        return (new FgtsDigitalReadinessResource(
            $this->query->readiness($request->clientId()),
        ))->response();
    }

    public function runs(
        ListFgtsDigitalRunsRequest $request,
    ): JsonResponse {
        return (new FgtsDigitalRunPageResource(
            $this->query->runs($request->filters()),
        ))->response();
    }

    public function sync(
        StartFgtsDigitalSyncRequest $request,
    ): JsonResponse {
        return (new FgtsDigitalRunResource(
            $this->synchronize->queue(
                $request->actor(),
                $request->syncData(),
            ),
        ))->response()->setStatusCode(202);
    }

    public function syncNow(
        ExecuteFgtsDigitalSyncRequest $request,
    ): JsonResponse {
        return (new FgtsDigitalRunResource(
            $this->synchronize->execute(
                $request->actor(),
                $request->syncData(),
            ),
        ))->response();
    }

    public function preview(
        PreviewFgtsDigitalGuideRequest $request,
    ): JsonResponse {
        return (new FgtsDigitalPreviewResource(
            $this->guides->preview(
                $request->actor(),
                $request->previewData(),
            ),
        ))->response();
    }

    public function emit(
        EmitFgtsDigitalGuideRequest $request,
    ): JsonResponse {
        $result = $this->guides->emit(
            $request->actor(),
            $request->emissionData(),
        );

        return (new FgtsDigitalEmissionResource($result))
            ->response()
            ->setStatusCode($result->reused ? 200 : 202);
    }

    public function importSession(
        ImportFgtsDigitalSessionRequest $request,
    ): JsonResponse {
        return (new FgtsDigitalSessionResource(
            $this->credentials->importSession($request->importData()),
        ))->response()->setStatusCode(201);
    }

    public function storeRepresentation(
        StoreFgtsDigitalRepresentationRequest $request,
    ): JsonResponse {
        return (new FgtsDigitalRepresentationResource(
            $this->credentials->storeRepresentation(
                $request->actor(),
                $request->representationData(),
            ),
        ))->response()->setStatusCode(201);
    }
}
