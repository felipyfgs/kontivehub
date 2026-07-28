<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\TenantProxyPowerSyncData;
use App\DTO\Tenant\TenantProxyPowerSyncResult;
use App\Exceptions\TenantSerproAuthorizationApiException;
use App\Models\Client;
use App\Services\Integra\ClientProcuracaoSyncService;
use App\Support\CurrentTenant;
use RuntimeException;

final readonly class SyncTenantProxyPowersAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private ClientProcuracaoSyncService $procuracaoSync,
    ) {}

    public function __invoke(
        TenantProxyPowerSyncData $data,
    ): TenantProxyPowerSyncResult {
        $tenant = $this->currentTenant->tenant();
        $client = Client::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($data->clientId);

        try {
            $result = $this->procuracaoSync->syncOfficial(
                $tenant,
                $client,
                $data->environment,
                $data->actorUserId,
            );
        } catch (RuntimeException $error) {
            throw TenantSerproAuthorizationApiException::operationFailed(
                $error->getMessage(),
            );
        }

        return new TenantProxyPowerSyncResult(
            powers: $result['powers'],
            sync: $result['sync'],
        );
    }
}
