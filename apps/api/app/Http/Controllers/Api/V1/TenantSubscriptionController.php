<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tenant\ShowSubscriptionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ViewSubscriptionRequest;
use App\Http\Resources\TenantSubscriptionResource;
use Illuminate\Http\JsonResponse;

final class TenantSubscriptionController extends Controller
{
    public function show(
        ViewSubscriptionRequest $request,
        ShowSubscriptionAction $action,
    ): JsonResponse {
        return TenantSubscriptionResource::make($action())->response();
    }
}
