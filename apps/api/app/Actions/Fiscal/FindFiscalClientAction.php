<?php

namespace App\Actions\Fiscal;

use App\Models\Client;
use App\Models\Tenant;

final class FindFiscalClientAction
{
    public function handle(Tenant $tenant, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($clientId)
            ->first();
    }
}
