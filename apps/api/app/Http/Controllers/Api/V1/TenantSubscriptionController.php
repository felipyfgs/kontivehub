<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tenant\ShowTenantSubscriptionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ViewTenantSubscriptionRequest;
use App\Http\Resources\TenantSubscriptionResource;
use Illuminate\Http\JsonResponse;

final class TenantSubscriptionController extends Controller
{
    public function show(
        ViewTenantSubscriptionRequest $request,
        ShowTenantSubscriptionAction $action,
    ): JsonResponse {
        return TenantSubscriptionResource::make($action())->response();
    }
}
