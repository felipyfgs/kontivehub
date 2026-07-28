<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tenant\ConfirmTenantAutXmlEnrollmentAction;
use App\Actions\Tenant\EnrollTenantAutXmlAction;
use App\Actions\Tenant\InactivateTenantAutXmlEnrollmentAction;
use App\Actions\Tenant\ShowTenantAutXmlCursorAction;
use App\Actions\Tenant\ShowTenantAutXmlOverviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ConfirmTenantAutXmlEnrollmentRequest;
use App\Http\Requests\Tenant\EnrollTenantAutXmlRequest;
use App\Http\Requests\Tenant\InactivateTenantAutXmlEnrollmentRequest;
use App\Http\Requests\Tenant\ViewTenantAutXmlCursorRequest;
use App\Http\Requests\Tenant\ViewTenantAutXmlRequest;
use App\Http\Resources\TenantAutXmlCursorOverviewResource;
use App\Http\Resources\TenantAutXmlEnrollmentResource;
use App\Http\Resources\TenantAutXmlOverviewResource;
use App\Models\TenantAutXmlEnrollment;
use Illuminate\Http\JsonResponse;

final class TenantAutXmlController extends Controller
{
    public function overview(
        ViewTenantAutXmlRequest $request,
        ShowTenantAutXmlOverviewAction $action,
    ): JsonResponse {
        return TenantAutXmlOverviewResource::make(
            $action($request->perPage()),
        )->response();
    }

    public function cursor(
        ViewTenantAutXmlCursorRequest $request,
        ShowTenantAutXmlCursorAction $action,
    ): JsonResponse {
        return TenantAutXmlCursorOverviewResource::make($action())->response();
    }

    public function enroll(
        EnrollTenantAutXmlRequest $request,
        EnrollTenantAutXmlAction $action,
    ): JsonResponse {
        return TenantAutXmlEnrollmentResource::make(
            $action($request->establishmentId()),
        )->response()->setStatusCode(201);
    }

    public function confirm(
        ConfirmTenantAutXmlEnrollmentRequest $request,
        TenantAutXmlEnrollment $enrollment,
        ConfirmTenantAutXmlEnrollmentAction $action,
    ): JsonResponse {
        return TenantAutXmlEnrollmentResource::make(
            $action((int) $enrollment->id, $request->actor()),
        )->response();
    }

    public function inactivate(
        InactivateTenantAutXmlEnrollmentRequest $request,
        TenantAutXmlEnrollment $enrollment,
        InactivateTenantAutXmlEnrollmentAction $action,
    ): JsonResponse {
        return TenantAutXmlEnrollmentResource::make(
            $action((int) $enrollment->id),
        )->response();
    }
}
