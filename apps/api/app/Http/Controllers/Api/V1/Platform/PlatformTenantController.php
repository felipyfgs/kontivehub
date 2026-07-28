<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Actions\Platform\CreatePendingTenantAction;
use App\Actions\Platform\ListPlatformTenantsAction;
use App\Actions\Platform\RegenerateTenantActivationAction;
use App\Actions\Platform\ShowPlatformTenantAction;
use App\Actions\Platform\UpdatePendingTenantFirstAdminAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\CreatePendingTenantRequest;
use App\Http\Requests\Platform\ListPlatformTenantAdminRequest;
use App\Http\Requests\Platform\RegenerateTenantActivationRequest;
use App\Http\Requests\Platform\ShowPlatformTenantRequest;
use App\Http\Requests\Platform\UpdatePendingTenantFirstAdminRequest;
use App\Http\Resources\ActivationDeliveryResource;
use App\Http\Resources\PlatformTenantAdminDetailResource;
use App\Http\Resources\PlatformTenantAdminSummaryResource;
use App\Models\Tenant;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Administração de Tenants (criação pendente, detalhe, regeneração, correção do 1º ADMIN).
 */
class PlatformTenantController extends Controller
{
    public function __construct(
        private readonly ListPlatformTenantsAction $listTenants,
        private readonly ShowPlatformTenantAction $showTenant,
        private readonly CreatePendingTenantAction $createTenant,
        private readonly RegenerateTenantActivationAction $regenerateActivation,
        private readonly UpdatePendingTenantFirstAdminAction $updateFirstAdmin,
    ) {}

    public function index(ListPlatformTenantAdminRequest $request): AnonymousResourceCollection
    {
        return PlatformTenantAdminSummaryResource::collection(
            ($this->listTenants)($request->toDto()),
        );
    }

    public function show(ShowPlatformTenantRequest $request, Tenant $tenant): PlatformTenantAdminDetailResource
    {
        return PlatformTenantAdminDetailResource::make(
            ($this->showTenant)($tenant),
        );
    }

    public function store(CreatePendingTenantRequest $request): ActivationDeliveryResource
    {
        return ActivationDeliveryResource::make(
            ($this->createTenant)($request->toDto(), $request->actor()),
        );
    }

    public function regenerateActivation(
        RegenerateTenantActivationRequest $request,
        Tenant $tenant,
    ): ActivationDeliveryResource {
        return ActivationDeliveryResource::make(
            ($this->regenerateActivation)($tenant, $request->toDto(), $request->actor()),
        );
    }

    public function updateFirstAdmin(
        UpdatePendingTenantFirstAdminRequest $request,
        Tenant $tenant,
    ): ActivationDeliveryResource {
        return ActivationDeliveryResource::make(
            ($this->updateFirstAdmin)($tenant, $request->toDto(), $request->actor()),
        );
    }
}
