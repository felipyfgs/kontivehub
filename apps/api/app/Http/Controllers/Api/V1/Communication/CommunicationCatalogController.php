<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\CreateCommunicationCannedResponseAction;
use App\Actions\Communication\CreateCommunicationLabelAction;
use App\Actions\Communication\DeactivateCommunicationCannedResponseAction;
use App\Actions\Communication\DeleteCommunicationCannedResponseAction;
use App\Actions\Communication\DeleteCommunicationLabelAction;
use App\Actions\Communication\DuplicateCommunicationCannedResponseAction;
use App\Actions\Communication\RenderCommunicationCannedResponseAction;
use App\Actions\Communication\UpdateCommunicationCannedResponseAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\DuplicateCommunicationCannedResponseRequest;
use App\Http\Requests\Communication\ListCommunicationCannedResponsesRequest;
use App\Http\Requests\Communication\ListCommunicationLabelsRequest;
use App\Http\Requests\Communication\ManageCommunicationCannedResponseRequest;
use App\Http\Requests\Communication\ManageCommunicationLabelRequest;
use App\Http\Requests\Communication\RenderCommunicationCannedResponseRequest;
use App\Http\Requests\Communication\StoreCannedResponseRequest;
use App\Http\Requests\Communication\StoreCommunicationLabelRequest;
use App\Http\Requests\Communication\UpdateCannedResponseRequest;
use App\Http\Requests\Communication\ViewCommunicationOutboundCapabilitiesRequest;
use App\Http\Resources\Communication\CommunicationCannedResponseCollection;
use App\Http\Resources\Communication\CommunicationCannedResponseRenderResource;
use App\Http\Resources\Communication\CommunicationCannedResponseResource;
use App\Http\Resources\Communication\CommunicationLabelResource;
use App\Http\Resources\Communication\CommunicationOutboundCapabilitiesResource;
use App\Models\CommunicationCannedResponse;
use App\Models\CommunicationLabel;
use App\Services\Communication\Canned\CommunicationCannedResponseQuery;
use App\Services\Communication\Catalog\CommunicationCatalogQuery;
use Illuminate\Http\JsonResponse;

final class CommunicationCatalogController extends Controller
{
    public function __construct(
        private readonly CommunicationCatalogQuery $catalog,
        private readonly CreateCommunicationLabelAction $createLabel,
        private readonly DeleteCommunicationLabelAction $deleteLabel,
    ) {}

    public function labels(ListCommunicationLabelsRequest $request): JsonResponse
    {
        return CommunicationLabelResource::collection($this->catalog->labels())->response();
    }

    public function outboundCapabilities(
        ViewCommunicationOutboundCapabilitiesRequest $request,
    ): JsonResponse {
        return (new CommunicationOutboundCapabilitiesResource(
            $this->catalog->outboundCapabilities(),
        ))->response()->header('Cache-Control', 'private, no-store');
    }

    public function storeLabel(StoreCommunicationLabelRequest $request): JsonResponse
    {
        return (new CommunicationLabelResource(
            $this->createLabel->handle($request->labelData()),
        ))->response()->setStatusCode(201);
    }

    public function deleteLabel(
        ManageCommunicationLabelRequest $request,
        CommunicationLabel $label,
    ): JsonResponse {
        $this->deleteLabel->handle($label);

        return response()->json(status: 204);
    }

    public function cannedResponses(
        ListCommunicationCannedResponsesRequest $request,
        CommunicationCannedResponseQuery $query,
    ): JsonResponse {
        $filters = $request->filters();
        if ($filters->paginated) {
            return (new CommunicationCannedResponseCollection(
                $query->paginate($filters),
            ))->response();
        }

        return CommunicationCannedResponseResource::collection(
            $query->all($filters),
        )->response();
    }

    public function storeCannedResponse(
        StoreCannedResponseRequest $request,
        CreateCommunicationCannedResponseAction $action,
    ): JsonResponse {
        return (new CommunicationCannedResponseResource(
            $action->handle($request->mutationData()),
        ))->response()->setStatusCode(201);
    }

    public function updateCannedResponse(
        UpdateCannedResponseRequest $request,
        CommunicationCannedResponse $canned,
        UpdateCommunicationCannedResponseAction $action,
    ): JsonResponse {
        return (new CommunicationCannedResponseResource(
            $action->handle($canned, $request->mutationData()),
        ))->response();
    }

    public function duplicateCannedResponse(
        DuplicateCommunicationCannedResponseRequest $request,
        CommunicationCannedResponse $canned,
        DuplicateCommunicationCannedResponseAction $action,
    ): JsonResponse {
        return (new CommunicationCannedResponseResource(
            $action->handle($canned, $request->duplicationData()),
        ))->response()->setStatusCode(201);
    }

    public function deactivateCannedResponse(
        ManageCommunicationCannedResponseRequest $request,
        CommunicationCannedResponse $canned,
        DeactivateCommunicationCannedResponseAction $action,
    ): JsonResponse {
        return (new CommunicationCannedResponseResource(
            $action->handle($canned),
        ))->response();
    }

    public function renderCannedResponse(
        RenderCommunicationCannedResponseRequest $request,
        CommunicationCannedResponse $canned,
        RenderCommunicationCannedResponseAction $action,
    ): JsonResponse {
        return (new CommunicationCannedResponseRenderResource(
            $action->handle($canned, $request->renderData(), $request->actor()),
        ))->response();
    }

    public function deleteCannedResponse(
        ManageCommunicationCannedResponseRequest $request,
        CommunicationCannedResponse $canned,
        DeleteCommunicationCannedResponseAction $action,
    ): JsonResponse {
        $action->handle($canned);

        return response()->json(status: 204);
    }
}
