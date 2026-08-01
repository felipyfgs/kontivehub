<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Actions\Tenant\ListMembersAction;
use App\Actions\Tenant\MutateMemberAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\DeactivateMemberRequest;
use App\Http\Requests\Tenant\ReactivateMemberRequest;
use App\Http\Requests\Tenant\RegenerateMemberActivationRequest;
use App\Http\Requests\Tenant\StoreMemberRequest;
use App\Http\Requests\Tenant\UpdateMemberRecipientRequest;
use App\Http\Requests\Tenant\UpdateMemberRoleRequest;
use App\Http\Requests\Tenant\ViewMembersRequest;
use App\Http\Resources\TenantMemberDeliveryResource;
use App\Http\Resources\TenantMemberListResource;
use App\Http\Resources\TenantMemberResource;
use Illuminate\Http\JsonResponse;

final class TenantMemberController extends Controller
{
    public function index(
        ViewMembersRequest $request,
        ListMembersAction $action,
    ): JsonResponse {
        return TenantMemberListResource::make(
            $action($request->actor()),
        )->response();
    }

    public function store(
        StoreMemberRequest $request,
        MutateMemberAction $action,
    ): JsonResponse {
        return TenantMemberDeliveryResource::make(
            $action->create($request->actor(), $request->memberData()),
        )->response()->setStatusCode(201);
    }

    public function update(
        UpdateMemberRoleRequest $request,
        int $membership,
        MutateMemberAction $action,
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
        UpdateMemberRecipientRequest $request,
        int $membership,
        MutateMemberAction $action,
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
        DeactivateMemberRequest $request,
        int $membership,
        MutateMemberAction $action,
    ): JsonResponse {
        return TenantMemberResource::make(
            $action->deactivate($request->actor(), $membership),
        )->response();
    }

    public function reactivate(
        ReactivateMemberRequest $request,
        int $membership,
        MutateMemberAction $action,
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
        RegenerateMemberActivationRequest $request,
        int $membership,
        MutateMemberAction $action,
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
