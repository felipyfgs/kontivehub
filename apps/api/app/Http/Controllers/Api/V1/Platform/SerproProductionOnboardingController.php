<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Actions\Serpro\ManageSerproProductionOnboardingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ShowSerproProductionOnboardingRequest;
use App\Http\Requests\Serpro\StoreProductionOnboardingRequest;
use App\Http\Resources\SerproProductionOnboardingEnvelopeResource;
use Illuminate\Http\JsonResponse;

final class SerproProductionOnboardingController extends Controller
{
    public function __construct(
        private readonly ManageSerproProductionOnboardingAction $onboarding,
    ) {}

    public function show(
        ShowSerproProductionOnboardingRequest $request,
    ): SerproProductionOnboardingEnvelopeResource {
        return SerproProductionOnboardingEnvelopeResource::make(
            $this->onboarding->show($request->actor()),
        );
    }

    public function store(StoreProductionOnboardingRequest $request): JsonResponse
    {
        return SerproProductionOnboardingEnvelopeResource::make(
            $this->onboarding->activate(
                $request->actor(),
                $request->toDto(),
            ),
        )->response()->setStatusCode(201);
    }
}
