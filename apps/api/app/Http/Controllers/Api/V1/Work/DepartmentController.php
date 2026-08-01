<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Http\Controllers\Controller;
use App\Http\Requests\Work\AssignDepartmentMembershipRequest;
use App\Http\Requests\Work\ListDepartmentsRequest;
use App\Http\Requests\Work\StoreDepartmentRequest;
use App\Http\Requests\Work\UpdateDepartmentRequest;
use App\Http\Resources\Work\DepartmentAssignmentResource;
use App\Http\Resources\Work\DepartmentCollection;
use App\Http\Resources\Work\DepartmentResource;
use App\Models\WorkDepartment;
use App\Services\Work\DepartmentQuery;
use App\Services\Work\DepartmentService;
use Illuminate\Http\JsonResponse;

class DepartmentController extends Controller
{
    public function index(
        ListDepartmentsRequest $request,
        DepartmentQuery $query,
    ): JsonResponse {
        return (new DepartmentCollection(
            $query->paginate($request->filters()),
        ))->response();
    }

    public function store(
        StoreDepartmentRequest $request,
        DepartmentService $service,
    ): JsonResponse {
        return DepartmentResource::make(
            $service->create($request->department()),
        )->response()->setStatusCode(201);
    }

    public function update(
        UpdateDepartmentRequest $request,
        WorkDepartment $department,
        DepartmentService $service,
    ): JsonResponse {
        return DepartmentResource::make(
            $service->update($department, $request->department()),
        )->response();
    }

    public function assignMembership(
        AssignDepartmentMembershipRequest $request,
        WorkDepartment $department,
        DepartmentService $service,
    ): JsonResponse {
        return DepartmentAssignmentResource::make(
            $service->assignMembership(
                $department,
                $request->membership(),
            ),
        )->response();
    }
}
