<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Http\Controllers\Controller;
use App\Http\Requests\Work\AssignWorkDepartmentMembershipRequest;
use App\Http\Requests\Work\ListWorkDepartmentsRequest;
use App\Http\Requests\Work\StoreWorkDepartmentRequest;
use App\Http\Requests\Work\UpdateWorkDepartmentRequest;
use App\Http\Resources\WorkDepartmentAssignmentResource;
use App\Http\Resources\WorkDepartmentCollection;
use App\Http\Resources\WorkDepartmentResource;
use App\Models\WorkDepartment;
use App\Services\Work\WorkDepartmentQuery;
use App\Services\Work\WorkDepartmentService;
use Illuminate\Http\JsonResponse;

class WorkDepartmentController extends Controller
{
    public function index(
        ListWorkDepartmentsRequest $request,
        WorkDepartmentQuery $query,
    ): JsonResponse {
        return (new WorkDepartmentCollection(
            $query->paginate($request->filters()),
        ))->response();
    }

    public function store(
        StoreWorkDepartmentRequest $request,
        WorkDepartmentService $service,
    ): JsonResponse {
        return WorkDepartmentResource::make(
            $service->create($request->department()),
        )->response()->setStatusCode(201);
    }

    public function update(
        UpdateWorkDepartmentRequest $request,
        WorkDepartment $department,
        WorkDepartmentService $service,
    ): JsonResponse {
        return WorkDepartmentResource::make(
            $service->update($department, $request->department()),
        )->response();
    }

    public function assignMembership(
        AssignWorkDepartmentMembershipRequest $request,
        WorkDepartment $department,
        WorkDepartmentService $service,
    ): JsonResponse {
        return WorkDepartmentAssignmentResource::make(
            $service->assignMembership(
                $department,
                $request->membership(),
            ),
        )->response();
    }
}
