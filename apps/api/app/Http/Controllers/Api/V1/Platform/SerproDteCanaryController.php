<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Actions\Serpro\ManageDteCanaryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ApproveDteCanaryOwnerRequest;
use App\Http\Requests\Platform\CreateDteCanaryRequest;
use App\Http\Requests\Platform\DisableDteCanaryRequest;
use App\Http\Requests\Platform\ExecuteDteCanaryRequest;
use App\Http\Requests\Platform\GetDteCanarySummaryRequest;
use App\Http\Requests\Platform\PromoteLimitedDteCanaryRequest;
use App\Http\Requests\Platform\ReconcileDteCanaryRequest;
use App\Http\Requests\Platform\SelectDteCanaryTargetRequest;
use App\Http\Resources\SerproDteCanaryExecutionResource;
use App\Http\Resources\SerproDteCanaryRequestResource;
use App\Http\Resources\SerproDteCanarySummaryResource;
use App\Http\Resources\SerproDteControlResource;
use App\Models\SerproDteCanaryRequest;

/**
 * Superfície global do canário DTE (Proprietário / PLATFORM_ADMIN).
 * Nunca devolve payload fiscal — apenas resumo sanitizado.
 */
class SerproDteCanaryController extends Controller
{
    public function __construct(
        private readonly ManageDteCanaryAction $canary,
    ) {}

    public function summary(GetDteCanarySummaryRequest $request): SerproDteCanarySummaryResource
    {
        return SerproDteCanarySummaryResource::make(
            $this->canary->summary($request->toDto()),
        );
    }

    public function create(CreateDteCanaryRequest $request): SerproDteCanaryRequestResource
    {
        return SerproDteCanaryRequestResource::make(
            $this->canary->create($request->actor()),
        );
    }

    public function selectTarget(
        SelectDteCanaryTargetRequest $request,
        SerproDteCanaryRequest $serproDteCanaryRequest,
    ): SerproDteCanaryRequestResource {
        return SerproDteCanaryRequestResource::make(
            $this->canary->selectTarget(
                $serproDteCanaryRequest,
                $request->toDto(),
                $request->actor(),
            ),
        );
    }

    public function approveOwner(
        ApproveDteCanaryOwnerRequest $request,
        SerproDteCanaryRequest $serproDteCanaryRequest,
    ): SerproDteCanaryRequestResource {
        return SerproDteCanaryRequestResource::make(
            $this->canary->approveOwner(
                $serproDteCanaryRequest,
                $request->actor(),
            ),
        );
    }

    public function execute(
        ExecuteDteCanaryRequest $request,
        SerproDteCanaryRequest $serproDteCanaryRequest,
    ): SerproDteCanaryExecutionResource {
        return SerproDteCanaryExecutionResource::make(
            $this->canary->execute(
                $serproDteCanaryRequest,
                $request->actor(),
            ),
        );
    }

    public function reconcile(
        ReconcileDteCanaryRequest $request,
        SerproDteCanaryRequest $serproDteCanaryRequest,
    ): SerproDteCanaryRequestResource {
        return SerproDteCanaryRequestResource::make(
            $this->canary->reconcile(
                $serproDteCanaryRequest,
                $request->toDto(),
                $request->actor(),
            ),
        );
    }

    public function promoteLimited(
        PromoteLimitedDteCanaryRequest $request,
        SerproDteCanaryRequest $serproDteCanaryRequest,
    ): SerproDteControlResource {
        return SerproDteControlResource::make(
            $this->canary->promoteLimited(
                $serproDteCanaryRequest,
                $request->toDto(),
                $request->actor(),
            ),
        );
    }

    public function disable(DisableDteCanaryRequest $request): SerproDteControlResource
    {
        return SerproDteControlResource::make(
            $this->canary->disable($request->toDto(), $request->actor()),
        );
    }

    public function show(
        SerproDteCanaryRequest $serproDteCanaryRequest,
    ): SerproDteCanaryRequestResource {
        return SerproDteCanaryRequestResource::make($serproDteCanaryRequest);
    }
}
