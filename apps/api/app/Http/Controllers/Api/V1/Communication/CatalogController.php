<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\CreateCannedResponseAction;
use App\Actions\Communication\CreateLabelAction;
use App\Actions\Communication\DeactivateCannedResponseAction;
use App\Actions\Communication\DeleteCannedResponseAction;
use App\Actions\Communication\DeleteLabelAction;
use App\Actions\Communication\DuplicateCannedResponseAction;
use App\Actions\Communication\RenderCannedResponseAction;
use App\Actions\Communication\UpdateCannedResponseAction;
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
use App\Http\Resources\Communication\CannedResponseCollection;
use App\Http\Resources\Communication\CannedResponseRenderResource;
use App\Http\Resources\Communication\CannedResponseResource;
use App\Http\Resources\Communication\LabelResource;
use App\Http\Resources\Communication\OutboundCapabilitiesResource;
use App\Models\CommunicationCannedResponse;
use App\Models\CommunicationLabel;
use App\Services\Communication\Canned\CannedResponseQuery;
use App\Services\Communication\Catalog\CatalogQuery;
use Illuminate\Http\JsonResponse;

final class CatalogController extends Controller
{
    public function __construct(
        private readonly CatalogQuery $catalog,
        private readonly CreateLabelAction $createLabel,
        private readonly DeleteLabelAction $deleteLabel,
    ) {}

    public function labels(ListCommunicationLabelsRequest $request): JsonResponse
    {
        return LabelResource::collection($this->catalog->labels())->response();
    }

    public function outboundCapabilities(
        ViewCommunicationOutboundCapabilitiesRequest $request,
    ): JsonResponse {
        return (new OutboundCapabilitiesResource(
            $this->catalog->outboundCapabilities($request->user()),
        ))->response()->header('Cache-Control', 'private, no-store');
    }

    public function storeLabel(StoreCommunicationLabelRequest $request): JsonResponse
    {
        return (new LabelResource(
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
        CannedResponseQuery $query,
    ): JsonResponse {
        $filters = $request->filters();
        if ($filters->paginated) {
            return (new CannedResponseCollection(
                $query->paginate($filters),
            ))->response();
        }

        return CannedResponseResource::collection(
            $query->all($filters),
        )->response();
    }

    public function storeCannedResponse(
        StoreCannedResponseRequest $request,
        CreateCannedResponseAction $action,
    ): JsonResponse {
        return (new CannedResponseResource(
            $action->handle($request->mutationData()),
        ))->response()->setStatusCode(201);
    }

    public function updateCannedResponse(
        UpdateCannedResponseRequest $request,
        CommunicationCannedResponse $canned,
        UpdateCannedResponseAction $action,
    ): JsonResponse {
        return (new CannedResponseResource(
            $action->handle($canned, $request->mutationData()),
        ))->response();
    }

    public function duplicateCannedResponse(
        DuplicateCommunicationCannedResponseRequest $request,
        CommunicationCannedResponse $canned,
        DuplicateCannedResponseAction $action,
    ): JsonResponse {
        return (new CannedResponseResource(
            $action->handle($canned, $request->duplicationData()),
        ))->response()->setStatusCode(201);
    }

    public function deactivateCannedResponse(
        ManageCommunicationCannedResponseRequest $request,
        CommunicationCannedResponse $canned,
        DeactivateCannedResponseAction $action,
    ): JsonResponse {
        return (new CannedResponseResource(
            $action->handle($canned),
        ))->response();
    }

    public function renderCannedResponse(
        RenderCommunicationCannedResponseRequest $request,
        CommunicationCannedResponse $canned,
        RenderCannedResponseAction $action,
    ): JsonResponse {
        return (new CannedResponseRenderResource(
            $action->handle($canned, $request->renderData(), $request->actor()),
        ))->response();
    }

    public function deleteCannedResponse(
        ManageCommunicationCannedResponseRequest $request,
        CommunicationCannedResponse $canned,
        DeleteCannedResponseAction $action,
    ): JsonResponse {
        $action->handle($canned);

        return response()->json(status: 204);
    }
}
