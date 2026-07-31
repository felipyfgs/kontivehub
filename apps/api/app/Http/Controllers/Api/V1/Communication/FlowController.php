<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\CloneFlowAction;
use App\Actions\Communication\CreateFlowAction;
use App\Actions\Communication\DeleteFlowAction;
use App\Actions\Communication\InspectFlowGraphAction;
use App\Actions\Communication\ManageFlowBindingAction;
use App\Actions\Communication\PublishFlowAction;
use App\Actions\Communication\UpdateFlowAction;
use App\Actions\Communication\UpdateFlowDraftAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\CloneCommunicationFlowRequest;
use App\Http\Requests\Communication\CloneCommunicationFlowVersionRequest;
use App\Http\Requests\Communication\DryRunCommunicationFlowRequest;
use App\Http\Requests\Communication\InspectCommunicationFlowGraphRequest;
use App\Http\Requests\Communication\ManageCommunicationFlowRequest;
use App\Http\Requests\Communication\PublishCommunicationFlowRequest;
use App\Http\Requests\Communication\SetCommunicationFlowBindingStateRequest;
use App\Http\Requests\Communication\StoreCommunicationFlowBindingRequest;
use App\Http\Requests\Communication\StoreCommunicationFlowRequest;
use App\Http\Requests\Communication\UpdateCommunicationFlowBindingRequest;
use App\Http\Requests\Communication\UpdateCommunicationFlowDraftRequest;
use App\Http\Requests\Communication\UpdateCommunicationFlowRequest;
use App\Http\Requests\Communication\ViewCommunicationFlowRequest;
use App\Http\Resources\Communication\FlowBindingResource;
use App\Http\Resources\Communication\FlowDraftResource;
use App\Http\Resources\Communication\FlowDryRunResource;
use App\Http\Resources\Communication\FlowGraphValidationResource;
use App\Http\Resources\Communication\FlowPreviewResource;
use App\Http\Resources\Communication\FlowPublicationResource;
use App\Http\Resources\Communication\FlowResource;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowInboxBinding;
use App\Models\CommunicationFlowVersion;
use App\Services\Communication\Flows\FlowAvailability;
use App\Services\Communication\Flows\FlowQuery;
use Illuminate\Http\JsonResponse;

final class FlowController extends Controller
{
    public function index(
        ViewCommunicationFlowRequest $request,
        FlowQuery $query,
        FlowAvailability $availability,
    ): JsonResponse {
        return FlowResource::collection($query->all())
            ->additional([
                'meta' => [
                    'flows_enabled' => $availability->enabled(),
                ],
            ])
            ->response();
    }

    public function store(
        StoreCommunicationFlowRequest $request,
        CreateFlowAction $action,
    ): JsonResponse {
        return (new FlowResource(
            $action->execute($request->flowData()),
        ))->response()->setStatusCode(201);
    }

    public function show(
        ViewCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        FlowQuery $query,
    ): JsonResponse {
        return (new FlowResource(
            $query->detail($flow),
            detailed: true,
        ))->response();
    }

    public function update(
        UpdateCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        UpdateFlowAction $action,
    ): JsonResponse {
        return (new FlowResource(
            $action->execute($flow, $request->flowData()),
        ))->response();
    }

    public function destroy(
        ManageCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        DeleteFlowAction $action,
    ): JsonResponse {
        $action->execute($flow);

        return response()->json(status: 204);
    }

    public function showDraft(
        ViewCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        FlowQuery $query,
    ): JsonResponse {
        return (new FlowDraftResource(
            $query->draft($flow),
        ))->response();
    }

    public function updateDraft(
        UpdateCommunicationFlowDraftRequest $request,
        CommunicationFlow $flow,
        UpdateFlowDraftAction $action,
    ): JsonResponse {
        return (new FlowDraftResource(
            $action->execute($flow, $request->draftData()),
        ))->response();
    }

    public function validateGraph(
        InspectCommunicationFlowGraphRequest $request,
        CommunicationFlow $flow,
        InspectFlowGraphAction $action,
    ): JsonResponse {
        return (new FlowGraphValidationResource(
            $action->validate($flow, $request->graphData()),
        ))->response();
    }

    public function dryRun(
        DryRunCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        InspectFlowGraphAction $action,
    ): JsonResponse {
        return (new FlowDryRunResource(
            $action->dryRun($flow, $request->graphData()),
        ))->response();
    }

    public function previewGraph(
        InspectCommunicationFlowGraphRequest $request,
        CommunicationFlow $flow,
        InspectFlowGraphAction $action,
    ): JsonResponse {
        return (new FlowPreviewResource(
            $action->preview($flow, $request->graphData()),
        ))->response();
    }

    public function publish(
        PublishCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        PublishFlowAction $action,
    ): JsonResponse {
        return (new FlowPublicationResource(
            $action->execute($flow, $request->publicationData()),
        ))->response()->setStatusCode(201);
    }

    public function cloneFlow(
        CloneCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        CloneFlowAction $action,
    ): JsonResponse {
        return (new FlowResource(
            $action->execute($flow, $request->cloneData()),
        ))->response()->setStatusCode(201);
    }

    public function cloneVersion(
        CloneCommunicationFlowVersionRequest $request,
        CommunicationFlow $flow,
        CommunicationFlowVersion $version,
        CloneFlowAction $action,
    ): JsonResponse {
        return (new FlowResource(
            $action->execute($flow, $request->cloneData()),
        ))->response()->setStatusCode(201);
    }

    public function indexBindings(
        ViewCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        FlowQuery $query,
    ): JsonResponse {
        return FlowBindingResource::collection(
            $query->bindings($flow),
        )->response();
    }

    public function storeBinding(
        StoreCommunicationFlowBindingRequest $request,
        CommunicationFlow $flow,
        ManageFlowBindingAction $action,
    ): JsonResponse {
        return (new FlowBindingResource(
            $action->create($flow, $request->bindingData()),
        ))->response()->setStatusCode(201);
    }

    public function updateBinding(
        UpdateCommunicationFlowBindingRequest $request,
        CommunicationFlowInboxBinding $binding,
        ManageFlowBindingAction $action,
    ): JsonResponse {
        return (new FlowBindingResource(
            $action->update($binding, $request->bindingData()),
        ))->response();
    }

    public function enableBinding(
        SetCommunicationFlowBindingStateRequest $request,
        CommunicationFlowInboxBinding $binding,
        ManageFlowBindingAction $action,
    ): JsonResponse {
        return (new FlowBindingResource(
            $action->setEnabled($binding, $request->bindingData(), true),
        ))->response();
    }

    public function disableBinding(
        SetCommunicationFlowBindingStateRequest $request,
        CommunicationFlowInboxBinding $binding,
        ManageFlowBindingAction $action,
    ): JsonResponse {
        return (new FlowBindingResource(
            $action->setEnabled($binding, $request->bindingData(), false),
        ))->response();
    }

    public function destroyBinding(
        ManageCommunicationFlowRequest $request,
        CommunicationFlowInboxBinding $binding,
        ManageFlowBindingAction $action,
    ): JsonResponse {
        $action->delete($binding);

        return response()->json(status: 204);
    }
}
