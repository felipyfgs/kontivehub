<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\CloneCommunicationFlowAction;
use App\Actions\Communication\CreateCommunicationFlowAction;
use App\Actions\Communication\DeleteCommunicationFlowAction;
use App\Actions\Communication\InspectCommunicationFlowGraphAction;
use App\Actions\Communication\ManageCommunicationFlowBindingAction;
use App\Actions\Communication\PublishCommunicationFlowAction;
use App\Actions\Communication\UpdateCommunicationFlowAction;
use App\Actions\Communication\UpdateCommunicationFlowDraftAction;
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
use App\Http\Resources\Communication\CommunicationFlowBindingResource;
use App\Http\Resources\Communication\CommunicationFlowDraftResource;
use App\Http\Resources\Communication\CommunicationFlowDryRunResource;
use App\Http\Resources\Communication\CommunicationFlowGraphValidationResource;
use App\Http\Resources\Communication\CommunicationFlowPreviewResource;
use App\Http\Resources\Communication\CommunicationFlowPublicationResource;
use App\Http\Resources\Communication\CommunicationFlowResource;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowInboxBinding;
use App\Models\CommunicationFlowVersion;
use App\Services\Communication\Flows\CommunicationFlowAvailability;
use App\Services\Communication\Flows\CommunicationFlowQuery;
use Illuminate\Http\JsonResponse;

final class CommunicationFlowController extends Controller
{
    public function index(
        ViewCommunicationFlowRequest $request,
        CommunicationFlowQuery $query,
        CommunicationFlowAvailability $availability,
    ): JsonResponse {
        return CommunicationFlowResource::collection($query->all())
            ->additional([
                'meta' => [
                    'flows_enabled' => $availability->enabled(),
                ],
            ])
            ->response();
    }

    public function store(
        StoreCommunicationFlowRequest $request,
        CreateCommunicationFlowAction $action,
    ): JsonResponse {
        return (new CommunicationFlowResource(
            $action->execute($request->flowData()),
        ))->response()->setStatusCode(201);
    }

    public function show(
        ViewCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        CommunicationFlowQuery $query,
    ): JsonResponse {
        return (new CommunicationFlowResource(
            $query->detail($flow),
            detailed: true,
        ))->response();
    }

    public function update(
        UpdateCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        UpdateCommunicationFlowAction $action,
    ): JsonResponse {
        return (new CommunicationFlowResource(
            $action->execute($flow, $request->flowData()),
        ))->response();
    }

    public function destroy(
        ManageCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        DeleteCommunicationFlowAction $action,
    ): JsonResponse {
        $action->execute($flow);

        return response()->json(status: 204);
    }

    public function showDraft(
        ViewCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        CommunicationFlowQuery $query,
    ): JsonResponse {
        return (new CommunicationFlowDraftResource(
            $query->draft($flow),
        ))->response();
    }

    public function updateDraft(
        UpdateCommunicationFlowDraftRequest $request,
        CommunicationFlow $flow,
        UpdateCommunicationFlowDraftAction $action,
    ): JsonResponse {
        return (new CommunicationFlowDraftResource(
            $action->execute($flow, $request->draftData()),
        ))->response();
    }

    public function validateGraph(
        InspectCommunicationFlowGraphRequest $request,
        CommunicationFlow $flow,
        InspectCommunicationFlowGraphAction $action,
    ): JsonResponse {
        return (new CommunicationFlowGraphValidationResource(
            $action->validate($flow, $request->graphData()),
        ))->response();
    }

    public function dryRun(
        DryRunCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        InspectCommunicationFlowGraphAction $action,
    ): JsonResponse {
        return (new CommunicationFlowDryRunResource(
            $action->dryRun($flow, $request->graphData()),
        ))->response();
    }

    public function previewGraph(
        InspectCommunicationFlowGraphRequest $request,
        CommunicationFlow $flow,
        InspectCommunicationFlowGraphAction $action,
    ): JsonResponse {
        return (new CommunicationFlowPreviewResource(
            $action->preview($flow, $request->graphData()),
        ))->response();
    }

    public function publish(
        PublishCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        PublishCommunicationFlowAction $action,
    ): JsonResponse {
        return (new CommunicationFlowPublicationResource(
            $action->execute($flow, $request->publicationData()),
        ))->response()->setStatusCode(201);
    }

    public function cloneFlow(
        CloneCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        CloneCommunicationFlowAction $action,
    ): JsonResponse {
        return (new CommunicationFlowResource(
            $action->execute($flow, $request->cloneData()),
        ))->response()->setStatusCode(201);
    }

    public function cloneVersion(
        CloneCommunicationFlowVersionRequest $request,
        CommunicationFlow $flow,
        CommunicationFlowVersion $version,
        CloneCommunicationFlowAction $action,
    ): JsonResponse {
        return (new CommunicationFlowResource(
            $action->execute($flow, $request->cloneData()),
        ))->response()->setStatusCode(201);
    }

    public function indexBindings(
        ViewCommunicationFlowRequest $request,
        CommunicationFlow $flow,
        CommunicationFlowQuery $query,
    ): JsonResponse {
        return CommunicationFlowBindingResource::collection(
            $query->bindings($flow),
        )->response();
    }

    public function storeBinding(
        StoreCommunicationFlowBindingRequest $request,
        CommunicationFlow $flow,
        ManageCommunicationFlowBindingAction $action,
    ): JsonResponse {
        return (new CommunicationFlowBindingResource(
            $action->create($flow, $request->bindingData()),
        ))->response()->setStatusCode(201);
    }

    public function updateBinding(
        UpdateCommunicationFlowBindingRequest $request,
        CommunicationFlowInboxBinding $binding,
        ManageCommunicationFlowBindingAction $action,
    ): JsonResponse {
        return (new CommunicationFlowBindingResource(
            $action->update($binding, $request->bindingData()),
        ))->response();
    }

    public function enableBinding(
        SetCommunicationFlowBindingStateRequest $request,
        CommunicationFlowInboxBinding $binding,
        ManageCommunicationFlowBindingAction $action,
    ): JsonResponse {
        return (new CommunicationFlowBindingResource(
            $action->setEnabled($binding, $request->bindingData(), true),
        ))->response();
    }

    public function disableBinding(
        SetCommunicationFlowBindingStateRequest $request,
        CommunicationFlowInboxBinding $binding,
        ManageCommunicationFlowBindingAction $action,
    ): JsonResponse {
        return (new CommunicationFlowBindingResource(
            $action->setEnabled($binding, $request->bindingData(), false),
        ))->response();
    }

    public function destroyBinding(
        ManageCommunicationFlowRequest $request,
        CommunicationFlowInboxBinding $binding,
        ManageCommunicationFlowBindingAction $action,
    ): JsonResponse {
        $action->delete($binding);

        return response()->json(status: 204);
    }
}
