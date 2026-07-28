<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Http\Controllers\Controller;
use App\Http\Requests\Work\ListWorkProcessGroupsRequest;
use App\Http\Resources\WorkProcessGroupCollection;
use App\Services\Work\WorkProcessGroupQuery;
use Illuminate\Http\JsonResponse;

class WorkProcessGroupController extends Controller
{
    public function index(
        ListWorkProcessGroupsRequest $request,
        WorkProcessGroupQuery $query,
    ): JsonResponse {
        return (new WorkProcessGroupCollection(
            $query->paginate($request->filters()),
        ))->response();
    }
}
