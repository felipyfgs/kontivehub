<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tenant\ShowFiscalIdentityAction;
use App\Actions\Tenant\StoreFiscalIdentityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreFiscalIdentityRequest;
use App\Http\Requests\Tenant\ViewFiscalIdentityRequest;
use App\Http\Resources\TenantFiscalIdentityResource;
use App\Http\Resources\TenantFiscalIdentityStatusResource;
use Illuminate\Http\JsonResponse;

final class TenantFiscalCredentialController extends Controller
{
    public function showIdentity(
        ViewFiscalIdentityRequest $request,
        ShowFiscalIdentityAction $action,
    ): JsonResponse {
        return TenantFiscalIdentityStatusResource::make($action())->response();
    }

    public function storeIdentity(
        StoreFiscalIdentityRequest $request,
        StoreFiscalIdentityAction $action,
    ): JsonResponse {
        return TenantFiscalIdentityResource::make(
            $action($request->identityData()),
        )->response()->setStatusCode(201);
    }
}
