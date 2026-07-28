<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outbound\ManageOutboundCaptureAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Outbound\AccessOutboundSecretRequest;
use App\Http\Requests\Outbound\ActivateOutboundProfileRequest;
use App\Http\Requests\Outbound\ListOutboundNumbersRequest;
use App\Http\Requests\Outbound\ListOutboundProfilesRequest;
use App\Http\Requests\Outbound\ListOutboundRunsRequest;
use App\Http\Requests\Outbound\OperateOutboundRequest;
use App\Http\Requests\Outbound\ResetOutboundSeriesRequest;
use App\Http\Requests\Outbound\StoreOutboundCscRequest;
use App\Http\Requests\Outbound\StoreOutboundSeedRequest;
use App\Http\Requests\Outbound\UpdateOutboundKillSwitchRequest;
use App\Http\Requests\Outbound\UploadOutboundPackageRequest;
use App\Http\Requests\Outbound\ViewOutboundRequest;
use App\Http\Resources\Outbound\OutboundCaptureProfileResource;
use App\Http\Resources\Outbound\OutboundCaptureRunResource;
use App\Http\Resources\Outbound\OutboundCscResource;
use App\Http\Resources\Outbound\OutboundKillSwitchResource;
use App\Http\Resources\Outbound\OutboundKillSwitchStatusResource;
use App\Http\Resources\Outbound\OutboundNumberStateResource;
use App\Http\Resources\Outbound\OutboundPayloadResource;
use App\Http\Resources\Outbound\OutboundSeedResource;
use App\Http\Resources\Outbound\OutboundSeriesResource;
use App\Models\Establishment;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundSeriesCursor;
use App\Services\Outbound\OutboundCaptureQuery;
use App\Services\Outbound\OutboundKillSwitchService;
use Illuminate\Http\JsonResponse;

final class OutboundCaptureController extends Controller
{
    public function __construct(
        private readonly OutboundCaptureQuery $query,
        private readonly ManageOutboundCaptureAction $capture,
        private readonly OutboundKillSwitchService $killSwitch,
    ) {}

    public function indexProfiles(
        ListOutboundProfilesRequest $request,
    ): JsonResponse {
        return OutboundCaptureProfileResource::collection(
            $this->query->profiles($request->filters()),
        )->response();
    }

    public function showProfile(
        ViewOutboundRequest $request,
        OutboundCaptureProfile $profile,
    ): JsonResponse {
        return (new OutboundCaptureProfileResource($profile))->response();
    }

    public function storeSeed(
        StoreOutboundSeedRequest $request,
        Establishment $establishment,
    ): JsonResponse {
        return (new OutboundSeedResource(
            $this->capture->registerSeed(
                $request->actor(),
                $establishment,
                $request->seedData(),
            ),
        ))->response()->setStatusCode(201);
    }

    public function storeCsc(
        StoreOutboundCscRequest $request,
        OutboundCaptureProfile $profile,
    ): JsonResponse {
        return (new OutboundCscResource(
            $this->capture->storeCsc(
                $request->actor(),
                $profile,
                $request->cscData(),
            ),
        ))->response();
    }

    public function showCsc(
        AccessOutboundSecretRequest $request,
        OutboundCaptureProfile $profile,
    ): JsonResponse {
        return (new OutboundCscResource(
            $this->capture->revealCsc($request->actor(), $profile),
        ))->response();
    }

    public function activate(
        ActivateOutboundProfileRequest $request,
        OutboundCaptureProfile $profile,
    ): JsonResponse {
        return (new OutboundCaptureProfileResource(
            $this->capture->activate(
                $request->actor(),
                $profile,
                $request->activationData(),
            ),
        ))->response();
    }

    public function resetSeries(
        ResetOutboundSeriesRequest $request,
        OutboundSeriesCursor $series,
    ): JsonResponse {
        return (new OutboundSeriesResource(
            $this->capture->resetSeries(
                $request->actor(),
                $series,
                $request->resetData(),
            ),
        ))->response();
    }

    public function triggerQuery(
        OperateOutboundRequest $request,
        OutboundSeriesCursor $series,
    ): JsonResponse {
        return (new OutboundPayloadResource(
            $this->capture->triggerQuery($request->actor(), $series),
        ))->response();
    }

    public function uploadPackage(
        UploadOutboundPackageRequest $request,
        OutboundCaptureProfile $profile,
    ): JsonResponse {
        return (new OutboundPayloadResource(
            $this->capture->uploadPackage(
                $request->actor(),
                $profile,
                $request->packageData(),
            ),
        ))->response();
    }

    public function listSeries(
        ViewOutboundRequest $request,
        OutboundCaptureProfile $profile,
    ): JsonResponse {
        return OutboundSeriesResource::collection(
            $this->query->series($profile),
        )->response();
    }

    public function listNumbers(
        ListOutboundNumbersRequest $request,
        OutboundSeriesCursor $series,
    ): JsonResponse {
        return OutboundNumberStateResource::collection(
            $this->query->numbers($series, $request->filters()),
        )->response();
    }

    public function listRuns(
        ListOutboundRunsRequest $request,
    ): JsonResponse {
        return OutboundCaptureRunResource::collection(
            $this->query->runs($request->filters()),
        )->response();
    }

    public function killSwitch(
        UpdateOutboundKillSwitchRequest $request,
    ): JsonResponse {
        return (new OutboundKillSwitchResource(
            $this->capture->updateKillSwitch(
                $request->actor(),
                $request->killSwitchData(),
            ),
        ))->response();
    }

    public function killSwitchStatus(
        ViewOutboundRequest $request,
    ): JsonResponse {
        return (new OutboundKillSwitchStatusResource(
            $this->query->killSwitchStatus($this->killSwitch),
        ))->response();
    }
}
