<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tenant\ConfirmAutXmlEnrollmentAction;
use App\Actions\Tenant\EnrollAutXmlAction;
use App\Actions\Tenant\InactivateAutXmlEnrollmentAction;
use App\Actions\Tenant\ShowAutXmlCursorAction;
use App\Actions\Tenant\ShowAutXmlOverviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ConfirmAutXmlEnrollmentRequest;
use App\Http\Requests\Tenant\EnrollAutXmlRequest;
use App\Http\Requests\Tenant\InactivateAutXmlEnrollmentRequest;
use App\Http\Requests\Tenant\ViewAutXmlCursorRequest;
use App\Http\Requests\Tenant\ViewAutXmlRequest;
use App\Http\Resources\TenantAutXmlCursorOverviewResource;
use App\Http\Resources\TenantAutXmlEnrollmentResource;
use App\Http\Resources\TenantAutXmlOverviewResource;
use App\Models\TenantAutXmlEnrollment;
use Illuminate\Http\JsonResponse;

final class TenantAutXmlController extends Controller
{
    public function overview(
        ViewAutXmlRequest $request,
        ShowAutXmlOverviewAction $action,
    ): JsonResponse {
        return TenantAutXmlOverviewResource::make(
            $action($request->perPage()),
        )->response();
    }

    public function cursor(
        ViewAutXmlCursorRequest $request,
        ShowAutXmlCursorAction $action,
    ): JsonResponse {
        return TenantAutXmlCursorOverviewResource::make($action())->response();
    }

    public function enroll(
        EnrollAutXmlRequest $request,
        EnrollAutXmlAction $action,
    ): JsonResponse {
        return TenantAutXmlEnrollmentResource::make(
            $action($request->establishmentId()),
        )->response()->setStatusCode(201);
    }

    public function confirm(
        ConfirmAutXmlEnrollmentRequest $request,
        TenantAutXmlEnrollment $enrollment,
        ConfirmAutXmlEnrollmentAction $action,
    ): JsonResponse {
        return TenantAutXmlEnrollmentResource::make(
            $action((int) $enrollment->id, $request->actor()),
        )->response();
    }

    public function inactivate(
        InactivateAutXmlEnrollmentRequest $request,
        TenantAutXmlEnrollment $enrollment,
        InactivateAutXmlEnrollmentAction $action,
    ): JsonResponse {
        return TenantAutXmlEnrollmentResource::make(
            $action((int) $enrollment->id),
        )->response();
    }
}
