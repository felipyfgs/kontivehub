<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\CcmeiCertificateObservation;
use App\Models\CcmeiCertificateProjection;
use App\Models\Client;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Histórico local e disparo manual explícito de DADOSCCMEI122.
 */
final class CcmeiMonitoringQueryService
{
    public function __construct(private readonly FiscalMonitoringRunService $runs) {}

    /**
     * @return array<string, mixed>
     */
    public function history(Office $office, Client $client): array
    {
        $this->assertClient($office, $client);

        $projection = CcmeiCertificateProjection::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->first();
        $observations = CcmeiCertificateObservation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(static fn (CcmeiCertificateObservation $item): array => $item->toPublicArray())
            ->values()
            ->all();

        return [
            'client_id' => $client->id,
            'current' => $projection?->toPublicArray(),
            'history' => $observations,
            'provenance' => [
                'source' => 'local_projection',
                'serpro_called' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function enqueueManualConsult(Office $office, Client $client, ?int $actorUserId): array
    {
        $this->assertClient($office, $client);

        $run = $this->runs->enqueueManual(
            office: $office,
            client: $client,
            systemCode: 'INTEGRA_MEI',
            serviceCode: 'CCMEI',
            operationCode: 'MONITOR',
            competence: null,
            actorId: $actorUserId,
            correlationId: sprintf('ccmei-manual-%d-%s', $client->id, (string) Str::uuid()),
            dispatch: false,
        );
        $progress = is_array($run->progress) ? $run->progress : [];
        $progress['ccmei_manual'] = true;
        $run->forceFill(['progress' => $progress])->save();

        ExecuteFiscalMonitoringRunJob::dispatch($run->id)
            ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return method_exists($run, 'toPublicArray')
            ? $run->toPublicArray()
            : [
                'id' => $run->id,
                'client_id' => $run->client_id,
                'status' => $run->status?->value ?? (string) $run->status,
                'service_code' => $run->service_code,
                'operation_code' => $run->operation_code,
            ];
    }

    private function assertClient(Office $office, Client $client): void
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new HttpException(404, 'Cliente não encontrado no escritório atual.');
        }

    }
}
