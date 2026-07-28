<?php

namespace App\Actions\Fiscal;

use App\DTO\Fiscal\Monitoring\ClientFiscalRecordsData;
use App\Models\Client;
use App\Models\FiscalPnrRenunciation;
use App\Models\Tenant;

final class ListPnrRenunciationsAction
{
    public function handle(
        Tenant $tenant,
        int $clientId,
    ): ClientFiscalRecordsData {
        $client = Client::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($clientId)
            ->firstOrFail();

        $records = FiscalPnrRenunciation::query()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->latest('refreshed_at')
            ->latest('id')
            ->get();

        return new ClientFiscalRecordsData(
            clientId: (int) $client->id,
            records: $records,
        );
    }
}
