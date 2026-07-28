<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Actions\Serpro\ManageSerproPlatformOperationsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ApproveSerproRolloutRequest;
use App\Http\Requests\Platform\CreateSerproRolloutRequest;
use App\Http\Requests\Platform\FilterSerproEnvironmentRequest;
use App\Http\Requests\Platform\GetSerproReadinessRequest;
use App\Http\Requests\Platform\ListSerproBudgetsRequest;
use App\Http\Requests\Platform\ListSerproRolloutsRequest;
use App\Http\Requests\Platform\RejectSerproRolloutRequest;
use App\Http\Resources\SerproCredentialVersionResource;
use App\Http\Resources\SerproReadinessResource;
use App\Http\Resources\SerproRolloutApprovalResource;
use App\Http\Resources\SerproUsageBudgetResource;
use App\Models\SerproCredentialVersion;
use App\Models\SerproRolloutApproval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Console de plataforma SERPRO: credenciais, readiness, budgets e rollout.
 * PLATFORM_ADMIN; ações sensíveis exigem senha recente. Respostas sanitizadas.
 */
class SerproPlatformOpsController extends Controller
{
    public function __construct(
        private readonly ManageSerproPlatformOperationsAction $operations,
    ) {}

    public function listCredentialVersions(
        FilterSerproEnvironmentRequest $request,
    ): AnonymousResourceCollection {
        return SerproCredentialVersionResource::collection(
            $this->operations->credentialVersions($request->toDto()->environment),
        );
    }

    public function showCredentialVersion(
        SerproCredentialVersion $serproCredentialVersion,
    ): SerproCredentialVersionResource {
        return SerproCredentialVersionResource::make($serproCredentialVersion);
    }

    public function readiness(GetSerproReadinessRequest $request): SerproReadinessResource
    {
        return SerproReadinessResource::make(
            $this->operations->readiness($request->toDto(), $request->actor()),
        );
    }

    /**
     * Snapshot de métricas SERPRO sem PII (OAuth/gateway, breaker, filas, reconciliação).
     */
    public function metrics(): JsonResponse
    {
        return response()->json(['data' => $this->operations->metrics()]);
    }

    public function listBudgets(ListSerproBudgetsRequest $request): AnonymousResourceCollection
    {
        return SerproUsageBudgetResource::collection(
            $this->operations->budgets($request->toDto()),
        );
    }

    public function listRollouts(ListSerproRolloutsRequest $request): AnonymousResourceCollection
    {
        return SerproRolloutApprovalResource::collection(
            $this->operations->rollouts($request->toDto()),
        );
    }

    public function requestRollout(CreateSerproRolloutRequest $request): JsonResponse
    {
        return SerproRolloutApprovalResource::make(
            $this->operations->requestRollout($request->toDto(), $request->actor()),
        )->response()->setStatusCode(201);
    }

    public function approveRollout(
        ApproveSerproRolloutRequest $request,
        SerproRolloutApproval $serproRolloutApproval,
    ): SerproRolloutApprovalResource {
        $result = $this->operations->approveRollout(
            $serproRolloutApproval,
            $request->toDto(),
            $request->actor(),
        );

        return SerproRolloutApprovalResource::make($result->approval)->additional([
            'executed' => $result->executed,
            'kill_switch' => $result->killSwitch,
        ]);
    }

    public function rejectRollout(
        RejectSerproRolloutRequest $request,
        SerproRolloutApproval $serproRolloutApproval,
    ): SerproRolloutApprovalResource {
        return SerproRolloutApprovalResource::make(
            $this->operations->rejectRollout(
                $serproRolloutApproval,
                $request->toDto(),
                $request->actor(),
            ),
        );
    }
}
