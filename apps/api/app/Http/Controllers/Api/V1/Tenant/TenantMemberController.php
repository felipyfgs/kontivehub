<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Actions\Tenant\ListTenantMembersAction;
use App\Actions\Tenant\MutateTenantMemberAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\DeactivateTenantMemberRequest;
use App\Http\Requests\Tenant\ReactivateTenantMemberRequest;
use App\Http\Requests\Tenant\RegenerateTenantMemberActivationRequest;
use App\Http\Requests\Tenant\StoreTenantMemberRequest;
use App\Http\Requests\Tenant\UpdateTenantMemberRecipientRequest;
use App\Http\Requests\Tenant\UpdateTenantMemberRoleRequest;
use App\Http\Requests\Tenant\ViewTenantMembersRequest;
use App\Http\Resources\TenantMemberDeliveryResource;
use App\Http\Resources\TenantMemberListResource;
use App\Http\Resources\TenantMemberResource;
use Illuminate\Http\JsonResponse;

final class TenantMemberController extends Controller
{
    public function index(
        ViewTenantMembersRequest $request,
        ListTenantMembersAction $action,
    ): JsonResponse {
        return TenantMemberListResource::make(
            $action($request->actor()),
        )->response();
    }

    public function store(
        StoreTenantMemberRequest $request,
        MutateTenantMemberAction $action,
    ): JsonResponse {
        return TenantMemberDeliveryResource::make(
            $action->create($request->actor(), $request->memberData()),
        )->response()->setStatusCode(201);
    }

    public function update(
        UpdateTenantMemberRoleRequest $request,
        int $membership,
        MutateTenantMemberAction $action,
    ): JsonResponse {
        return TenantMemberResource::make(
            $action->changeRole(
                $request->actor(),
                $membership,
                $request->role(),
            ),
        )->response();
    }

    public function updateRecipient(
        UpdateTenantMemberRecipientRequest $request,
        int $membership,
        MutateTenantMemberAction $action,
    ): JsonResponse {
        return TenantMemberDeliveryResource::make(
            $action->correctRecipient(
                $request->actor(),
                $membership,
                $request->recipientData(),
            ),
        )->response();
    }

    public function deactivate(
        DeactivateTenantMemberRequest $request,
        int $membership,
        MutateTenantMemberAction $action,
    ): JsonResponse {
        return TenantMemberResource::make(
            $action->deactivate($request->actor(), $membership),
        )->response();
    }

    public function reactivate(
        ReactivateTenantMemberRequest $request,
        int $membership,
        MutateTenantMemberAction $action,
    ): JsonResponse {
        return TenantMemberDeliveryResource::make(
            $action->reactivate(
                $request->actor(),
                $membership,
                $request->deliveryMethod(),
            ),
        )->response();
    }

    public function regenerateActivation(
        RegenerateTenantMemberActivationRequest $request,
        int $membership,
        MutateTenantMemberAction $action,
    ): JsonResponse {
        return TenantMemberDeliveryResource::make(
            $action->regenerate(
                $request->actor(),
                $membership,
                $request->deliveryMethod(),
            ),
        )->response();
    }
}
