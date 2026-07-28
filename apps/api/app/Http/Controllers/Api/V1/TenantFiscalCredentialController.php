<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tenant\ShowTenantFiscalIdentityAction;
use App\Actions\Tenant\StoreTenantFiscalIdentityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreTenantFiscalIdentityRequest;
use App\Http\Requests\Tenant\ViewTenantFiscalIdentityRequest;
use App\Http\Resources\TenantFiscalIdentityResource;
use App\Http\Resources\TenantFiscalIdentityStatusResource;
use Illuminate\Http\JsonResponse;

final class TenantFiscalCredentialController extends Controller
{
    public function showIdentity(
        ViewTenantFiscalIdentityRequest $request,
        ShowTenantFiscalIdentityAction $action,
    ): JsonResponse {
        return TenantFiscalIdentityStatusResource::make($action())->response();
    }

    public function storeIdentity(
        StoreTenantFiscalIdentityRequest $request,
        StoreTenantFiscalIdentityAction $action,
    ): JsonResponse {
        return TenantFiscalIdentityResource::make(
            $action($request->identityData()),
        )->response()->setStatusCode(201);
    }
}
