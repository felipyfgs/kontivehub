<?php

namespace App\Actions\Fiscal;

use App\DTO\Fiscal\Monitoring\ClientFiscalRecordsData;
use App\DTO\Fiscal\Monitoring\FiscalRecordFilters;
use App\Models\Client;
use App\Models\FiscalRegistrationLink;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ListFiscalRegistrationLinksAction
{
    /** @return LengthAwarePaginator<int, FiscalRegistrationLink> */
    public function handle(
        Tenant $tenant,
        FiscalRecordFilters $filters,
    ): LengthAwarePaginator {
        $query = FiscalRegistrationLink::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('refreshed_at')
            ->orderByDesc('id');

        if ($filters->clientId !== null) {
            $query->where('client_id', $filters->clientId);
        }
        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }
        if ($filters->search !== null) {
            $this->applySearch($query, $tenant, $filters->search);
        }

        return $query->paginate($filters->perPage);
    }

    public function forClient(
        Tenant $tenant,
        int $clientId,
    ): ClientFiscalRecordsData {
        $client = Client::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($clientId)
            ->firstOrFail();

        $records = FiscalRegistrationLink::query()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->orderByDesc('refreshed_at')
            ->get();

        return new ClientFiscalRecordsData(
            clientId: (int) $client->id,
            records: $records,
        );
    }

    /** @param Builder<FiscalRegistrationLink> $query */
    private function applySearch(
        Builder $query,
        Tenant $tenant,
        string $search,
    ): void {
        $like = '%'.addcslashes($search, '%_\\').'%';
        $normalizedDigits = preg_replace('/\D+/', '', $search) ?: '';
        $digits = strlen($normalizedDigits) >= 8 ? $normalizedDigits : null;

        $query->where(function (Builder $filter) use ($search, $like, $digits, $tenant): void {
            $filter->where('link_key', 'like', $like)
                ->orWhere('source_provenance', 'like', $like)
                ->when(
                    ctype_digit($search),
                    fn (Builder $candidate) => $candidate->orWhere(
                        'client_id',
                        (int) $search,
                    ),
                )
                ->orWhereHas('client', function (Builder $client) use ($like, $digits, $tenant): void {
                    $client->where('tenant_id', $tenant->id)
                        ->where(function (Builder $identity) use ($like, $digits): void {
                            $identity->where('legal_name', 'like', $like)
                                ->orWhere('display_name', 'like', $like);
                            if ($digits !== null) {
                                $identity->orWhere('root_cnpj', 'like', '%'.$digits.'%');
                            }
                        });
                });
        });
    }
}
