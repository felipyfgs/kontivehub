<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Serpro\ManageTenantDteCanaryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ConfirmDteCanaryParticipationRequest;
use App\Http\Requests\Tenant\GetDteCanaryResultRequest;
use App\Http\Requests\Tenant\ListPendingDteCanaryRequest;
use App\Http\Resources\SerproDteCanaryRequestResource;
use App\Http\Resources\SerproDteCanaryTenantResultResource;
use App\Models\SerproDteCanaryRequest;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * Confirmação Tenant ADMIN e leitura do resultado DTE no tenant.
 * NÃO importa App\Services\Serpro\* — usa uma Action tenant-safe.
 * NÃO aceita tenant_id do client.
 */
class DteCanaryTenantController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly ManageTenantDteCanaryAction $canary,
    ) {}

    public function pending(
        ListPendingDteCanaryRequest $request,
    ): SerproDteCanaryRequestResource|JsonResponse {
        $canary = $this->canary->pending($this->currentTenant->tenant());

        return $canary instanceof SerproDteCanaryRequest
            ? SerproDteCanaryRequestResource::make($canary)
            : response()->json(['data' => null]);
    }

    public function confirmParticipation(
        ConfirmDteCanaryParticipationRequest $request,
        SerproDteCanaryRequest $serproDteCanaryRequest,
    ): SerproDteCanaryRequestResource {
        return SerproDteCanaryRequestResource::make(
            $this->canary->approve(
                $serproDteCanaryRequest,
                $request->actor(),
                $this->currentTenant->tenant(),
            ),
        );
    }

    public function result(
        GetDteCanaryResultRequest $request,
        SerproDteCanaryRequest $serproDteCanaryRequest,
    ): SerproDteCanaryTenantResultResource {
        return SerproDteCanaryTenantResultResource::make(
            $this->canary->result(
                $serproDteCanaryRequest,
                $request->actor(),
                $this->currentTenant->tenant(),
            ),
        );
    }
}
