<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\DefisLatestDeclarationArtifact;
use App\Models\Tenant;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Histórico local e disparo explícito da consulta DEFIS 143. */
final class DefisLatestDeclarationMonitoringQueryService
{
    public function __construct(private readonly FiscalMonitoringRunService $runs) {}

    /** @return array<string,mixed> */
    public function history(Tenant $tenant, Client $client, ?int $year = null): array
    {
        $this->assertClient($tenant, $client);
        $query = DefisLatestDeclarationArtifact::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('client_id', $client->id)
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
    public function enqueueManualConsult(Tenant $tenant, Client $client, int $year, ?int $actorUserId): array
    {
        $this->assertClient($tenant, $client);
        $year = (new DefisLatestDeclarationCodec)->assertCalendarYear($year);
        $run = $this->runs->enqueueManual(
            tenant: $tenant, client: $client, systemCode: 'INTEGRA_SN', serviceCode: 'DEFIS',
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

    public function findArtifact(Tenant $tenant, int $artifactId): ?DefisLatestDeclarationArtifact
    {
        return DefisLatestDeclarationArtifact::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->whereKey($artifactId)->first();
    }

    private function assertClient(Tenant $tenant, Client $client): void
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new HttpException(404, 'Cliente não encontrado no escritório atual.');
        }
    }
}
