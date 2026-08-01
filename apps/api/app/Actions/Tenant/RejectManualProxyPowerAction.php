<?php

namespace App\Actions\Tenant;

use App\Exceptions\TenantSerproAuthorizationApiException;
use App\Services\Integra\ClientProcuracaoSyncService;
use RuntimeException;

final readonly class RejectManualProxyPowerAction
{
    public function __construct(
        private ClientProcuracaoSyncService $procuracaoSync,
    ) {}

    public function __invoke(): never
    {
        try {
            $this->procuracaoSync->rejectManualOverride();
        } catch (RuntimeException $error) {
            throw TenantSerproAuthorizationApiException::manualProxyPowerRejected(
                $error->getMessage(),
            );
        }
    }
}
