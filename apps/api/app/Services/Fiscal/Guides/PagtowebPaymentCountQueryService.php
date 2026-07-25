<?php

namespace App\Services\Fiscal\Guides;

use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\Office;
use App\Models\PagtowebPaymentCountObservation;
use App\Models\PagtowebPaymentCountProjection;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PagtowebPaymentCountQueryService
{
    public function __construct(private readonly FiscalMonitoringRunService $runs, private readonly PagtowebPaymentCountCodec $codec) {}

    /** @return array<string,mixed> */
    public function history(Office $office, Client $client): array
    {
        $this->assertClient($office, $client);
        $projection = PagtowebPaymentCountProjection::query()->withoutGlobalScopes()->where('office_id', $office->id)->where('client_id', $client->id)->first();
        $history = PagtowebPaymentCountObservation::query()->withoutGlobalScopes()->where('office_id', $office->id)->where('client_id', $client->id)->orderByDesc('observed_at')->orderByDesc('id')->limit(50)->get()->map(static fn (PagtowebPaymentCountObservation $item) => $item->toPublicArray())->values()->all();

        return ['client_id' => $client->id, 'current' => $projection?->toPublicArray(), 'history' => $history, 'provenance' => ['source' => 'local_projection', 'serpro_called' => false]];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function enqueueManualConsult(Office $office, Client $client, array $filters, ?int $actorUserId): array
    {
        $this->assertClient($office, $client);
        $normalized = $this->codec->normalizeFilters($filters);
        $run = $this->runs->enqueueManual(office: $office, client: $client, systemCode: PagtowebPaymentCountAdapter::SYSTEM, serviceCode: PagtowebPaymentCountAdapter::SERVICE, operationCode: PagtowebPaymentCountAdapter::OPERATION, competence: null, actorId: $actorUserId, correlationId: sprintf('pagtoweb-count-%d-%s', $client->id, (string) Str::uuid()), dispatch: false);
        $progress = is_array($run->progress) ? $run->progress : [];
        $progress['pagtoweb_payment_count_manual'] = true;
        $progress['pagtoweb_payment_count_filters'] = $normalized['filter_summary'];
        $run->forceFill(['progress' => $progress])->save();
        ExecuteFiscalMonitoringRunJob::dispatch($run->id)->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return method_exists($run, 'toPublicArray') ? $run->toPublicArray() : ['id' => $run->id, 'client_id' => $run->client_id, 'status' => $run->status?->value ?? (string) $run->status];
    }

    private function assertClient(Office $office, Client $client): void
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new HttpException(404, 'Cliente não encontrado no escritório atual.');
        }
    }
}
