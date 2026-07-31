<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\SerproAuthorConfigurationData;
use App\Exceptions\TenantSerproAuthorizationApiException;
use App\Models\TenantSerproAuthorization;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Support\CurrentTenant;
use RuntimeException;

final readonly class ConfigureTenantSerproAuthorAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantSerproAuthorizationService $authorizations,
    ) {}

    public function __invoke(
        SerproAuthorConfigurationData $data,
    ): TenantSerproAuthorization {
        try {
            return $this->authorizations->configureAuthor(
                $this->currentTenant->tenant(),
                $data->environment,
                $data->identityType,
                $data->identity,
                $data->authorName,
                $data->certificateMode,
                $data->actorUserId,
            );
        } catch (RuntimeException $error) {
            throw TenantSerproAuthorizationApiException::operationFailed(
                $error->getMessage(),
            );
        }
    }
}
