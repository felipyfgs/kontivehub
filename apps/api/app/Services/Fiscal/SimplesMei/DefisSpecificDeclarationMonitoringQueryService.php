<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\DefisDeclarationProjection;
use App\Models\DefisDeclarationReference;
use App\Models\DefisSpecificDeclarationArtifact;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Histórico local e disparo explícito da declaração/recibo DEFIS 144. */
final class DefisSpecificDeclarationMonitoringQueryService
{
    public function __construct(private readonly FiscalMonitoringRunService $runs) {}

    /** @return array<string, mixed> */
    public function history(Office $office, Client $client, ?int $referenceId = null): array
    {
        $this->assertClient($office, $client);
        $references = DefisDeclarationProjection::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)->where('client_id', $client->id)
            ->whereNotNull('defis_declaration_reference_id')
            ->orderByDesc('calendar_year')->orderBy('declaration_type')
            ->get(['calendar_year', 'declaration_type', 'last_observed_at', 'defis_declaration_reference_id'])
            ->map(static fn (DefisDeclarationProjection $projection): array => [
                'reference_id' => $projection->defis_declaration_reference_id,
                'calendar_year' => $projection->calendar_year,
                'declaration_type' => $projection->declaration_type,
                'observed_at' => $projection->last_observed_at?->toIso8601String(),
            ])->values()->all();

        $documents = [];
        if ($referenceId !== null) {
            $this->findReference($office, $client, $referenceId);
            $documents = DefisSpecificDeclarationArtifact::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->where('client_id', $client->id)
                ->where('defis_declaration_reference_id', $referenceId)
                ->with('evidenceArtifact')->orderByDesc('observed_at')->orderBy('kind')->get()
                ->map(static fn (DefisSpecificDeclarationArtifact $artifact): array => $artifact->toPublicArray())->values()->all();
        }

        return [
            'client_id' => $client->id,
            'references' => $references,
            'documents' => $documents,
            'provenance' => ['source' => 'LOCAL_VAULT_DESCRIPTOR', 'serpro_called' => false],
        ];
    }

    /** @return array<string, mixed> */
    public function enqueueManualConsult(Office $office, Client $client, int $referenceId, ?int $actorUserId): array
    {
        $this->assertClient($office, $client);
        $this->findReference($office, $client, $referenceId);
        $run = $this->runs->enqueueManual(
            office: $office, client: $client, systemCode: 'INTEGRA_SN', serviceCode: 'DEFIS',
            operationCode: 'CONSULTAR_DECLARACAO_RECIBO', competence: null, actorId: $actorUserId,
            correlationId: sprintf('defis-144-manual-%d-%s', $client->id, (string) Str::uuid()), dispatch: false,
        );
        $progress = is_array($run->progress) ? $run->progress : [];
        $progress['defis_144_manual'] = true;
        $progress['defis_reference_id'] = $referenceId;
        $run->forceFill(['progress' => $progress])->save();
        ExecuteFiscalMonitoringRunJob::dispatch($run->id)->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return $run->toPublicArray();
    }

    public function findArtifact(Office $office, int $artifactId): ?DefisSpecificDeclarationArtifact
    {
        return DefisSpecificDeclarationArtifact::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)->whereKey($artifactId)->first();
    }

    private function findReference(Office $office, Client $client, int $referenceId): DefisDeclarationReference
    {
        $reference = DefisDeclarationReference::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)->where('client_id', $client->id)->find($referenceId);
        if ($reference === null) {
            throw new HttpException(404, 'Referência de declaração DEFIS não encontrada no escritório atual.');
        }

        return $reference;
    }

    private function assertClient(Office $office, Client $client): void
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new HttpException(404, 'Cliente não encontrado no escritório atual.');
        }
    }
}
