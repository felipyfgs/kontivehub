<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\FiscalIdentityData;
use App\Exceptions\TenantFiscalIdentityApiException;
use App\Models\TenantFiscalIdentity;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\TenantFiscalIdentityService;
use InvalidArgumentException;
use RuntimeException;

final readonly class StoreTenantFiscalIdentityAction
{
    public function __construct(
        private TenantFiscalIdentityService $identities,
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        FiscalIdentityData $data,
    ): TenantFiscalIdentity {
        try {
            $identity = $this->identities->upsertActive(
                $data->cnpj,
                $data->legalName,
            );
        } catch (InvalidArgumentException|RuntimeException) {
            $this->audit->record(
                'tenant_fiscal_identity.upsert',
                'FAILED',
                context: ['reason_code' => 'invalid_identity'],
            );

            throw TenantFiscalIdentityApiException::mutationFailed();
        }

        $this->audit->record(
            'tenant_fiscal_identity.upsert',
            'SUCCESS',
            $identity,
            ['identity_id' => $identity->id],
        );

        return $identity;
    }
}
