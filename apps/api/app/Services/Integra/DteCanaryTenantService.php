<?php

namespace App\Services\Integra;

use App\Models\Office;
use App\Models\SerproDteCanaryRequest;
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

    public function approveAsOfficeAdmin(
        SerproDteCanaryRequest $request,
        User $admin,
        Office $currentOffice,
        bool $passwordRecentlyConfirmed,
    ): SerproDteCanaryRequest {
        return $this->canary->approveAsOfficeAdmin(
            $request,
            $admin,
            $currentOffice,
            $passwordRecentlyConfirmed,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function tenantResult(
        SerproDteCanaryRequest $request,
        User $user,
        Office $currentOffice,
    ): array {
        return $this->canary->tenantResult($request, $user, $currentOffice);
    }

    public function findPendingForOffice(int $officeId): ?SerproDteCanaryRequest
    {
        return SerproDteCanaryRequest::query()
            ->where('office_id', $officeId)
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
