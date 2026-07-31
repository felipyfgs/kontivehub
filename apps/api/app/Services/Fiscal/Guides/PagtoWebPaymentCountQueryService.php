<?php

namespace App\Services\Fiscal\Guides;

use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\PagtowebPaymentCountObservation;
use App\Models\PagtowebPaymentCountProjection;
use App\Models\Tenant;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PagtoWebPaymentCountQueryService
{
    public function __construct(private readonly FiscalMonitoringRunService $runs, private readonly PagtoWebPaymentCountCodec $codec) {}

    /** @return array<string,mixed> */
    public function history(Tenant $tenant, Client $client): array
    {
        $this->assertClient($tenant, $client);
        $projection = PagtowebPaymentCountProjection::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('client_id', $client->id)->first();
        $history = PagtowebPaymentCountObservation::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('client_id', $client->id)->orderByDesc('observed_at')->orderByDesc('id')->limit(50)->get()->map(static fn (PagtowebPaymentCountObservation $item) => $item->toPublicArray())->values()->all();

        return ['client_id' => $client->id, 'current' => $projection?->toPublicArray(), 'history' => $history, 'provenance' => ['source' => 'local_projection', 'serpro_called' => false]];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function enqueueManualConsult(Tenant $tenant, Client $client, array $filters, ?int $actorUserId): array
    {
        $this->assertClient($tenant, $client);
        $normalized = $this->codec->normalizeFilters($filters);
        $run = $this->runs->enqueueManual(tenant: $tenant, client: $client, systemCode: PagtoWebPaymentCountAdapter::SYSTEM, serviceCode: PagtoWebPaymentCountAdapter::SERVICE, operationCode: PagtoWebPaymentCountAdapter::OPERATION, competence: null, actorId: $actorUserId, correlationId: sprintf('pagtoweb-count-%d-%s', $client->id, (string) Str::uuid()), dispatch: false);
        $progress = is_array($run->progress) ? $run->progress : [];
        $progress['pagtoweb_payment_count_manual'] = true;
        $progress['pagtoweb_payment_count_filters'] = $normalized['filter_summary'];
        $run->forceFill(['progress' => $progress])->save();
        ExecuteFiscalMonitoringRunJob::dispatch($run->id)->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return method_exists($run, 'toPublicArray') ? $run->toPublicArray() : ['id' => $run->id, 'client_id' => $run->client_id, 'status' => $run->status?->value ?? (string) $run->status];
    }

    private function assertClient(Tenant $tenant, Client $client): void
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new HttpException(404, 'Cliente não encontrado no escritório atual.');
        }
    }
}
