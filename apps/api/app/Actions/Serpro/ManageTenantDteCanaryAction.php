<?php

namespace App\Actions\Serpro;

use App\Exceptions\DteCanaryApiException;
use App\Models\SerproDteCanaryRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Integra\DteCanaryTenantService;
use App\Services\Serpro\SerproDteCanaryException;
use Closure;

final readonly class ManageTenantDteCanaryAction
{
    public function __construct(
        private DteCanaryTenantService $canary,
    ) {}

    public function pending(Tenant $tenant): ?SerproDteCanaryRequest
    {
        return $this->canary->findPendingForTenant($tenant->id);
    }

    public function approve(
        SerproDteCanaryRequest $request,
        User $actor,
        Tenant $tenant,
    ): SerproDteCanaryRequest {
        return $this->adapt(
            fn (): SerproDteCanaryRequest => $this->canary->approveAsTenantAdmin(
                $request,
                $actor,
                $tenant,
            ),
            'dte_tenant_confirm_error',
            422,
        );
    }

    public function result(
        SerproDteCanaryRequest $request,
        User $actor,
        Tenant $tenant,
    ): SerproDteCanaryRequest {
        return $this->adapt(
            fn (): SerproDteCanaryRequest => $this->canary->tenantResult(
                $request,
                $actor,
                $tenant,
            ),
            'dte_result_forbidden',
            403,
        );
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function adapt(
        Closure $operation,
        string $stableCode,
        int $httpStatus,
    ): mixed {
        try {
            return $operation();
        } catch (SerproDteCanaryException $error) {
            throw DteCanaryApiException::fromDomain($error, $stableCode, $httpStatus);
        }
    }
}
