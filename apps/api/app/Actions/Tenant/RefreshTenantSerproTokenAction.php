<?php

namespace App\Actions\Tenant;

use App\Enums\SerproEnvironment;
use App\Exceptions\TenantSerproAuthorizationApiException;
use App\Models\TenantSerproAuthorization;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Support\CurrentTenant;
use RuntimeException;

final readonly class RefreshTenantSerproTokenAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantSerproAuthorizationService $authorizations,
    ) {}

    public function __invoke(
        SerproEnvironment $environment,
        int $actorUserId,
    ): TenantSerproAuthorization {
        try {
            return $this->authorizations->refreshProcuradorToken(
                $this->currentTenant->tenant(),
                $environment,
                $actorUserId,
            );
        } catch (RuntimeException $error) {
            throw TenantSerproAuthorizationApiException::operationFailed(
                $error->getMessage(),
            );
        }
    }
}
