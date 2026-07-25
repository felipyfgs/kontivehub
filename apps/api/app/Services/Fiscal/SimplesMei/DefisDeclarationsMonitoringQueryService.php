<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\DefisDeclarationProjection;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Histórico local e disparo manual explícito da consulta DEFIS 142. */
final class DefisDeclarationsMonitoringQueryService
{
    public function __construct(private readonly FiscalMonitoringRunService $runs) {}

    /** @return array<string, mixed> */
    public function history(Office $office, Client $client): array
    {
        $this->assertClient($office, $client);
        $items = DefisDeclarationProjection::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->orderByDesc('calendar_year')
            ->orderBy('declaration_type')
            ->get()
            ->map(static fn (DefisDeclarationProjection $projection): array => $projection->toPublicArray())
            ->values()
            ->all();

        return [
            'client_id' => $client->id,
            'declarations' => $items,
            'provenance' => ['source' => 'LOCAL_PROJECTION', 'serpro_called' => false],
        ];
    }

    /** @return array<string, mixed> */
    public function enqueueManualConsult(Office $office, Client $client, ?int $actorUserId): array
    {
        $this->assertClient($office, $client);
        $run = $this->runs->enqueueManual(
            office: $office,
            client: $client,
            systemCode: 'INTEGRA_SN',
            serviceCode: 'DEFIS',
            operationCode: 'CONSULTAR',
            competence: null,
            actorId: $actorUserId,
            correlationId: sprintf('defis-142-manual-%d-%s', $client->id, (string) Str::uuid()),
            dispatch: false,
        );
        $progress = is_array($run->progress) ? $run->progress : [];
        $progress['defis_142_manual'] = true;
        $run->forceFill(['progress' => $progress])->save();
        ExecuteFiscalMonitoringRunJob::dispatch($run->id)
            ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return $run->toPublicArray();
    }

    private function assertClient(Office $office, Client $client): void
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new HttpException(404, 'Cliente não encontrado no escritório atual.');
        }
    }
}
