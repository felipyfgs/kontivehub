<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Http\Controllers\Controller;
use App\Http\Requests\Work\ListProcessGroupsRequest;
use App\Http\Resources\Work\ProcessGroupCollection;
use App\Services\Work\ProcessGroupQuery;
use Illuminate\Http\JsonResponse;

class ProcessGroupController extends Controller
{
    public function index(
        ListProcessGroupsRequest $request,
        ProcessGroupQuery $query,
    ): JsonResponse {
        return (new ProcessGroupCollection(
            $query->paginate($request->filters()),
        ))->response();
    }
}
