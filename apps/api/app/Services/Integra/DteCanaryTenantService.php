<?php

namespace App\Services\Integra;

use App\Models\SerproDteCanaryRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Serpro\SerproDteCanaryService;

/**
 * Fachada tenant-safe do canário DTE.
 * Controllers tenant importam esta classe (não App\Services\Serpro\*).
 */
final class DteCanaryTenantService
{
    public function __construct(
        private readonly SerproDteCanaryService $canary,
    ) {}

    public function approveAsTenantAdmin(
        SerproDteCanaryRequest $request,
        User $admin,
        Tenant $currentTenant,
    ): SerproDteCanaryRequest {
        return $this->canary->approveAsTenantAdmin(
            $request,
            $admin,
            $currentTenant,
        );
    }

    public function tenantResult(
        SerproDteCanaryRequest $request,
        User $user,
        Tenant $currentTenant,
    ): SerproDteCanaryRequest {
        return $this->canary->tenantResult($request, $user, $currentTenant);
    }

    public function findPendingForTenant(int $tenantId): ?SerproDteCanaryRequest
    {
        return SerproDteCanaryRequest::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [
                'TARGET_SET',
                'PARTIAL_APPROVED',
                'FULLY_APPROVED',
                'DISPATCHED',
                'SUCCEEDED',
                'FAILED',
                'UNCERTAIN',
                'RECONCILED',
            ])
            ->orderByDesc('id')
            ->first();
    }
}
