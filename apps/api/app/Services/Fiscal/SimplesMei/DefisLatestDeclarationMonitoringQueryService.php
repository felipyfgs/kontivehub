<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\DefisLatestDeclarationArtifact;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Histórico local e disparo explícito da consulta DEFIS 143. */
final class DefisLatestDeclarationMonitoringQueryService
{
    public function __construct(private readonly FiscalMonitoringRunService $runs) {}

    /** @return array<string,mixed> */
    public function history(Office $office, Client $client, ?int $year = null): array
    {
        $this->assertClient($office, $client);
        $query = DefisLatestDeclarationArtifact::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)->where('client_id', $client->id)
            ->with('evidenceArtifact')->orderByDesc('calendar_year')->orderBy('kind');
        if ($year !== null) {
            $query->where('calendar_year', $year);
        }

        return [
            'client_id' => $client->id,
            'documents' => $query->get()->map(static fn (DefisLatestDeclarationArtifact $artifact): array => $artifact->toPublicArray())->values()->all(),
            'provenance' => ['source' => 'LOCAL_VAULT_DESCRIPTOR', 'serpro_called' => false],
        ];
    }

    /** @return array<string,mixed> */
    public function enqueueManualConsult(Office $office, Client $client, int $year, ?int $actorUserId): array
    {
        $this->assertClient($office, $client);
        $year = (new DefisLatestDeclarationCodec)->assertCalendarYear($year);
        $run = $this->runs->enqueueManual(
            office: $office, client: $client, systemCode: 'INTEGRA_SN', serviceCode: 'DEFIS',
            operationCode: 'CONSULTAR_ULTIMA_DECLARACAO_RECIBO', competence: null, actorId: $actorUserId,
            correlationId: sprintf('defis-143-manual-%d-%s', $client->id, (string) Str::uuid()), dispatch: false,
        );
        $progress = is_array($run->progress) ? $run->progress : [];
        $progress['defis_143_manual'] = true;
        $progress['calendar_year'] = $year;
        $run->forceFill(['progress' => $progress])->save();
        ExecuteFiscalMonitoringRunJob::dispatch($run->id)->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return $run->toPublicArray();
    }

    public function findArtifact(Office $office, int $artifactId): ?DefisLatestDeclarationArtifact
    {
        return DefisLatestDeclarationArtifact::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)->whereKey($artifactId)->first();
    }

    private function assertClient(Office $office, Client $client): void
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new HttpException(404, 'Cliente não encontrado no escritório atual.');
        }
    }
}
