<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\InstitutionalProfileUpdateData;
use App\DTO\Tenant\InstitutionalProfileUpdateResult;
use App\Exceptions\TenantSettingsApiException;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\TenantInstitutionalProfileService;
use InvalidArgumentException;
use RuntimeException;

final readonly class UpdateInstitutionalProfileAction
{
    public function __construct(
        private TenantInstitutionalProfileService $profiles,
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        InstitutionalProfileUpdateData $data,
    ): InstitutionalProfileUpdateResult {
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

        return new InstitutionalProfileUpdateResult(
            profile: $result['profile'],
            cnpjChanged: $result['cnpj_changed'],
            invalidated: $result['invalidated'],
        );
    }
}
