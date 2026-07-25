<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;
use App\Models\Client;
use App\Models\FiscalCompetence;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Consultas tenant-scoped de declarações/recibos/extratos via núcleo fiscal.
 */
final class SimplesMeiQueryService
{
    public function __construct(
        private readonly FiscalMonitoringRunService $runs,
        private readonly RegimeApplicabilityService $regimes,
    ) {}

    /**
     * Enfileira consulta catalogada (idempotente por correlation_id).
     */
    public function enqueueConsult(
        Office $office,
        Client $client,
        string $systemCode,
        string $serviceCode,
        string $operationCode = 'MONITOR',
        ?string $periodKey = null,
        ?int $actorId = null,
        ?string $correlationId = null,
        bool $dispatch = true,
    ): FiscalMonitoringRun {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }

        $def = SimplesMeiCatalog::find($systemCode, $serviceCode, $operationCode);
        if ($def === null) {
            throw new RuntimeException(
                "Operação não catalogada para Simples/MEI: {$systemCode}/{$serviceCode}/{$operationCode}"
            );
        }

        $competence = null;
        if ($periodKey !== null && $periodKey !== '') {
            $competence = $this->regimes->projectCompetenceSituation(
                $office,
                $client,
                $def,
                $periodKey,
                FiscalSituation::Unknown,
                FiscalCoverage::Unknown,
            );
        }

        return $this->runs->enqueueManual(
            office: $office,
            client: $client,
            systemCode: $def->systemCode,
            serviceCode: $def->serviceCode,
            operationCode: $def->operationCode,
            competence: $competence,
            actorId: $actorId,
            correlationId: $correlationId ?? (string) Str::uuid(),
            dispatch: $dispatch,
        );
    }

    /**
     * Transmissão mutante — sempre passa pelo núcleo; adapter bloqueia no piloto.
     */
    public function enqueueTransmit(
        Office $office,
        Client $client,
        string $serviceCode,
        ?string $periodKey = null,
        ?int $actorId = null,
        ?string $correlationId = null,
        bool $dispatch = true,
    ): FiscalMonitoringRun {
        $serviceCode = strtoupper($serviceCode);
        $map = [
            'PGDASD' => ['INTEGRA_SN', 'PGDASD', 'TRANSMITIR'],
            'DEFIS' => ['INTEGRA_SN', 'DEFIS', 'TRANSMITIR'],
            'DASN_SIMEI' => ['INTEGRA_MEI', 'DASN_SIMEI', 'TRANSMITIR'],
        ];

        if (! isset($map[$serviceCode])) {
            throw new RuntimeException("Serviço de transmissão não suportado: {$serviceCode}");
        }

        [$system, $service, $op] = $map[$serviceCode];

        return $this->enqueueConsult(
            $office,
            $client,
            $system,
            $service,
            $op,
            $periodKey,
            $actorId,
            $correlationId,
            $dispatch,
        );
    }

    public function enqueueDasGeneration(
        Office $office,
        Client $client,
        string $regimeFamily = 'SIMPLES_NACIONAL',
        ?string $periodKey = null,
        ?int $actorId = null,
        ?string $correlationId = null,
        bool $dispatch = true,
    ): FiscalMonitoringRun {
        if (strtoupper($regimeFamily) === 'MEI') {
            return $this->enqueueConsult(
                $office, $client, 'INTEGRA_MEI', 'PGMEI', 'GERAR_DAS',
                $periodKey, $actorId, $correlationId, $dispatch,
            );
        }

        return $this->enqueueConsult(
            $office, $client, 'INTEGRA_SN', 'PGDASD', 'GERAR_DAS',
            $periodKey, $actorId, $correlationId, $dispatch,
        );
    }

    /**
     * @return Collection<int, FiscalCompetence>
     */
    public function listCompetences(Office $office, Client $client, ?string $regimeFamily = null): Collection
    {
        $q = FiscalCompetence::query()
            ->withoutGlobalScopes()
            ->with('category')
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->orderByDesc('period_key');

        if ($regimeFamily !== null) {
            $code = strtoupper($regimeFamily) === 'MEI' ? 'MEI' : 'SIMPLES_NACIONAL';
            $q->whereHas('category', fn ($c) => $c->where('code', $code));
        }

        return $q->get();
    }

    /**
     * @return LengthAwarePaginator<int, FiscalSnapshot>
     */
    public function listSnapshots(
        Office $office,
        Client $client,
        int $perPage = 50,
        ?string $systemCode = null,
    ): LengthAwarePaginator {
        $q = FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where(function ($w): void {
                $w->where('system_code', 'INTEGRA_SN')
                    ->orWhere('system_code', 'INTEGRA_MEI');
            })
            ->orderByDesc('id');

        if ($systemCode !== null) {
            $q->where('system_code', strtoupper($systemCode));
        }

        return $q->paginate($perPage);
    }
}
