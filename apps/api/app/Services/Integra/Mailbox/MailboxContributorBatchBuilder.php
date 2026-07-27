<?php

namespace App\Services\Integra\Mailbox;

use App\DTO\Serpro\FiscalIdentity;
use App\Enums\AuthorIdentityType;
use App\Enums\ClientProcuracaoSyncStatus;
use App\Models\Establishment;
use App\Models\Tenant;

/** Monta lotes PJ determinísticos e estritamente isolados por escritório. */
final class MailboxContributorBatchBuilder
{
    /** @return list<array{client_id:int,ni:string}> */
    public function contributors(Tenant|int $tenant): array
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : $tenant;

        return Establishment::query()
            ->withoutGlobalScopes()
            ->join('clients', function ($join): void {
                $join->on('clients.id', '=', 'establishments.client_id')
                    ->on('clients.tenant_id', '=', 'establishments.tenant_id');
            })
            ->join('client_procuracao_syncs', function ($join): void {
                $join->on('client_procuracao_syncs.client_id', '=', 'clients.id')
                    ->on('client_procuracao_syncs.tenant_id', '=', 'clients.tenant_id');
            })
            ->where('establishments.tenant_id', $tenantId)
            ->where('establishments.is_active', true)
            ->where('establishments.capture_enabled', true)
            ->where('clients.is_active', true)
            ->where('client_procuracao_syncs.status', ClientProcuracaoSyncStatus::Authorized->value)
            ->where(function ($query): void {
                $query->whereNull('client_procuracao_syncs.valid_to')
                    ->orWhere('client_procuracao_syncs.valid_to', '>=', now());
            })
            ->orderBy('establishments.cnpj')
            ->orderBy('clients.id')
            ->get(['clients.id as client_id', 'establishments.cnpj'])
            ->map(function ($row): ?array {
                try {
                    $ni = FiscalIdentity::fromNumero((string) $row->cnpj, AuthorIdentityType::Cnpj)->numero;
                } catch (\Throwable) {
                    return null;
                }

                return ['client_id' => (int) $row->client_id, 'ni' => $ni];
            })
            ->filter()
            ->unique('ni')
            ->values()
            ->all();
    }

    /** @return list<list<string>> */
    public function batches(Tenant|int $tenant, int $size = 1000): array
    {
        $size = max(1, min(1000, $size));
        $numbers = array_column($this->contributors($tenant), 'ni');

        return array_values(array_chunk($numbers, $size));
    }

    /** @return array<string, int> */
    public function clientMap(Tenant|int $tenant): array
    {
        $map = [];
        foreach ($this->contributors($tenant) as $contributor) {
            $map[$contributor['ni']] = $contributor['client_id'];
        }

        return $map;
    }
}
