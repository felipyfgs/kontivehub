<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\Mutations\ManageMonitoringMembershipAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\ListMonitoringModuleMembershipRequest;
use App\Http\Requests\Fiscal\Mutations\ManageMonitoringMembershipRequest;
use App\Http\Resources\Fiscal\MonitoringModuleMembershipResource;
use App\Services\FiscalMonitoring\MonitoringModuleMembershipService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Include/exclude de clientes na carteira de monitoramento (opt-out tenant-scoped).
 */
class MonitoringModuleMembershipController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly MonitoringModuleMembershipService $membership,
        private readonly ManageMonitoringMembershipAction $manage,
    ) {}

    public function index(
        ListMonitoringModuleMembershipRequest $request,
    ): JsonResponse|AnonymousResourceCollection {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();
        if ($filters->module === null) {
            return response()->json(['message' => 'Módulo inválido.'], 422);
        }

        $items = $this->membership->listExclusions(
            $tenant,
            $filters->module,
            $filters->submodule,
        );

        return MonitoringModuleMembershipResource::collection($items);
    }

    public function exclude(ManageMonitoringMembershipRequest $request): JsonResponse
    {
        try {
            $result = $this->manage->exclude(
                $request->actor(),
                $request->membershipData(),
            );
        } catch (UnprocessableEntityHttpException|RuntimeException $e) {
            return $this->failure($e);
        }

        return response()->json(['data' => $result]);
    }

    public function include(ManageMonitoringMembershipRequest $request): JsonResponse
    {
        try {
            $result = $this->manage->include($request->membershipData());
        } catch (UnprocessableEntityHttpException|RuntimeException $e) {
            return $this->failure($e);
        }

        $status = $result['errors'] !== [] && $result['included'] === 0 ? 422 : 200;

        return response()->json(['data' => $result], $status);
    }

    private function failure(\Throwable $error): JsonResponse
    {
        $text = $error->getMessage();

        return response()->json(['message' => $text], 422);
    }
}
