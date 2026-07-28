<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\TenantInstitutionalProfileUpdateData;
use App\DTO\Tenant\TenantInstitutionalProfileUpdateResult;
use App\Exceptions\TenantSettingsApiException;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\TenantInstitutionalProfileService;
use InvalidArgumentException;
use RuntimeException;

final readonly class UpdateTenantInstitutionalProfileAction
{
    public function __construct(
        private TenantInstitutionalProfileService $profiles,
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        TenantInstitutionalProfileUpdateData $data,
    ): TenantInstitutionalProfileUpdateResult {
        try {
            $result = $this->profiles->update(
                $data->attributes,
                $data->actorUserId,
            );
        } catch (InvalidArgumentException|RuntimeException $error) {
            $this->audit->record('tenant.institutional_profile.update', 'FAILED', null, [
                'message' => $error->getMessage(),
            ], $data->actorUserId);

            throw TenantSettingsApiException::profileUpdateFailed($error->getMessage());
        }

        return new TenantInstitutionalProfileUpdateResult(
            profile: $result['profile'],
            cnpjChanged: $result['cnpj_changed'],
            invalidated: $result['invalidated'],
        );
    }
}
