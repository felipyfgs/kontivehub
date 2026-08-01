<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tenant\QueryUsageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ViewUsageRequest;
use App\Http\Resources\TenantUsageEntriesResource;
use App\Http\Resources\TenantUsageSummaryResource;
use Illuminate\Http\JsonResponse;

final class TenantSerproUsageController extends Controller
{
    public function summary(
        ViewUsageRequest $request,
        QueryUsageAction $action,
    ): JsonResponse {
        return TenantUsageSummaryResource::make(
            $action->summary($request->year(), $request->month()),
        )->response();
    }

    public function entries(
        ViewUsageRequest $request,
        QueryUsageAction $action,
    ): JsonResponse {
        $resource = TenantUsageEntriesResource::make(
            $action->entries(
                perPage: $request->perPage(),
                year: $request->year(),
                month: $request->month(),
                sort: $request->string('sort')->toString(),
                direction: $request->string('direction')->toString(),
            ),
        );

        return response()->json($resource->resolve($request));
    }
}
