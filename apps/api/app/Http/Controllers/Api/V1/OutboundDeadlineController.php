<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outbound\ManageOutboundDeadlineAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Outbound\AdvanceOutboundTargetRequest;
use App\Http\Requests\Outbound\ConfirmPartialOutboundExportRequest;
use App\Http\Requests\Outbound\ExportOutboundMonthlyRequest;
use App\Http\Requests\Outbound\InspectOutboundCompetenceRequest;
use App\Http\Requests\Outbound\ListOutboundContingencyRequest;
use App\Http\Requests\Outbound\ListOutboundPendingRequest;
use App\Http\Resources\Outbound\OutboundCapacityForecastResource;
use App\Http\Resources\Outbound\OutboundCompetenceSummaryResource;
use App\Http\Resources\Outbound\OutboundMonthlyExportResource;
use App\Http\Resources\Outbound\OutboundMonthlyReadinessResource;
use App\Http\Resources\Outbound\OutboundPayloadResource;
use App\Http\Resources\Outbound\OutboundPendingCollection;
use App\Http\Resources\Outbound\OutboundTargetAdvanceResource;
use App\Services\Outbound\OutboundDeadlineQuery;
use Illuminate\Http\JsonResponse;

/**
 * Visão de fechamento mensal / capacidade — tenancy do servidor.
 */
final class OutboundDeadlineController extends Controller
{
    public function __construct(
        private readonly OutboundDeadlineQuery $query,
        private readonly ManageOutboundDeadlineAction $deadline,
    ) {}

    public function competenceSummary(
        InspectOutboundCompetenceRequest $request,
    ): JsonResponse {
        return (new OutboundCompetenceSummaryResource(
            $this->query->competenceSummary($request->competenceFilter()),
        ))->response();
    }

    public function capacityForecast(
        InspectOutboundCompetenceRequest $request,
    ): JsonResponse {
        return (new OutboundCapacityForecastResource(
            $this->query->capacityForecast($request->competenceFilter()),
        ))->response();
    }

    public function pendingItems(
        ListOutboundPendingRequest $request,
    ): JsonResponse {
        return (new OutboundPendingCollection(
            $this->query->pending($request->filters()),
        ))->response();
    }

    public function contingencyBatch(
        ListOutboundContingencyRequest $request,
    ): JsonResponse {
        return (new OutboundPayloadResource(
            $this->query->contingency($request->competenceFilter()),
        ))->response();
    }

    public function confirmPartialExport(
        ConfirmPartialOutboundExportRequest $request,
    ): JsonResponse {
        return (new OutboundMonthlyReadinessResource(
            $this->deadline->confirmPartial(
                $request->actor(),
                $request->confirmationData(),
            ),
        ))->response()->setStatusCode(200);
    }

    public function metrics(
        InspectOutboundCompetenceRequest $request,
    ): JsonResponse {
        return (new OutboundPayloadResource(
            $this->query->metrics($request->competenceFilter()),
        ))->response();
    }

    public function exportMonthly(
        ExportOutboundMonthlyRequest $request,
    ): JsonResponse {
        return (new OutboundMonthlyExportResource(
            $this->deadline->exportMonthly(
                $request->actor(),
                $request->exportData(),
            ),
        ))->response()->setStatusCode(202);
    }

    public function advanceTarget(
        AdvanceOutboundTargetRequest $request,
    ): JsonResponse {
        return (new OutboundTargetAdvanceResource(
            $this->deadline->advanceTarget($request->targetData()),
        ))->response();
    }
}
