<?php

namespace App\Services\Fiscal\Guides;

use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\PagtowebPaymentListItem;
use App\Models\PagtowebPaymentListProjection;
use App\Models\Tenant;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PagtoWebPaymentListQueryService
{
    public function __construct(private readonly FiscalMonitoringRunService $runs, private readonly PagtoWebPaymentListCodec $codec) {}

    /** @return array<string,mixed> */
    public function history(Tenant $tenant, Client $client, int $page = 1, int $perPage = 50): array
    {
        $this->assertClient($tenant, $client);
        $projection = PagtowebPaymentListProjection::query()->withoutGlobalScopes()->with('observation')->where('tenant_id', $tenant->id)->where('client_id', $client->id)->first();
        if ($projection?->last_observation_id === null) {
            return ['client_id' => $client->id, 'current' => null, 'items' => [], 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0], 'provenance' => ['source' => 'local_projection', 'serpro_called' => false]];
        }
        $items = PagtowebPaymentListItem::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('client_id', $client->id)->where('observation_id', $projection->last_observation_id)->orderBy('id')->paginate(perPage: $perPage, page: $page);
        $observation = $projection->observation;
        $current = $observation?->toPublicArray() ?? ['observed_at' => $projection->last_valid_query_at?->toIso8601String(), 'source_provenance' => $projection->source_provenance];

        return ['client_id' => $client->id, 'current' => $current, 'items' => $items->getCollection()->map(static fn (PagtowebPaymentListItem $item) => $item->toPublicArray())->values()->all(), 'meta' => ['page' => $items->currentPage(), 'per_page' => $items->perPage(), 'total' => $items->total()], 'provenance' => ['source' => 'local_projection', 'serpro_called' => false]];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function enqueueManualConsult(Tenant $tenant, Client $client, array $filters, ?int $actorUserId): array
    {
        $this->assertClient($tenant, $client);
        $normalized = $this->codec->normalizeFilters($filters);
        $run = $this->runs->enqueueManual(tenant: $tenant, client: $client, systemCode: PagtoWebPaymentListAdapter::SYSTEM, serviceCode: PagtoWebPaymentListAdapter::SERVICE, operationCode: PagtoWebPaymentListAdapter::OPERATION, competence: null, actorId: $actorUserId, correlationId: sprintf('pagtoweb-list-%d-%s', $client->id, (string) Str::uuid()), dispatch: false);
        $progress = is_array($run->progress) ? $run->progress : [];
        $persistedFilters = $normalized['filter_summary'];
        unset($persistedFilters['numero_documento_digests']);
        $progress['pagtoweb_payment_list_filters'] = $persistedFilters;
        if ($normalized['document_numbers'] !== []) {
            $progress['pagtoweb_payment_list_documents_encrypted'] = $this->codec->encryptDocumentNumbers(
                $normalized['document_numbers'],
            );
        }
        $run->forceFill(['progress' => $progress])->save();
        ExecuteFiscalMonitoringRunJob::dispatch($run->id)->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return method_exists($run, 'toPublicArray') ? $run->toPublicArray() : ['id' => $run->id, 'client_id' => $run->client_id];
    }

    private function assertClient(Tenant $tenant, Client $client): void
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new HttpException(404, 'Cliente não encontrado no escritório atual.');
        }
    }
}
