<?php

namespace App\Services\Fiscal\SimplesMei\Pgmei;

use App\Enums\PgmeiDebtState;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\Office;
use App\Models\PgmeiDebtItem;
use App\Models\PgmeiDebtObservation;
use App\Models\PgmeiDebtProjection;
use App\Models\TaxGuide;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Consultas tenant-scoped da carteira/histórico PGMEI (leitura local + enqueue manual).
 */
final class PgmeiMonitoringQueryService
{
    public const MANUAL_BATCH_LIMIT = 100;

    public function __construct(
        private readonly FiscalMonitoringRunService $runs,
        private readonly PgmeiCommunicationService $communication,
    ) {}

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    public function portfolioDetails(Office $office, array $clientIds, ?int $year = null): array
    {
        if ($clientIds === []) {
            return [];
        }

        $tz = is_string($office->timezone) && $office->timezone !== ''
            ? $office->timezone
            : 'America/Sao_Paulo';
        $year ??= (int) CarbonImmutable::now($tz)->year;
        $year = PgmeiYear::assertValid($year);
        $now = CarbonImmutable::now();

        $projections = PgmeiDebtProjection::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereIn('client_id', $clientIds)
            ->where('calendar_year', $year)
            ->get()
            ->keyBy('client_id');

        $communications = $this->communication->summariesForClients($office, $clientIds);

        $map = [];
        foreach ($clientIds as $cid) {
            $proj = $projections->get($cid);
            $comm = $communications[$cid] ?? [
                'automatic_requested' => false,
                'automatic_effective' => false,
                'execution_mode' => 'TEMPLATE_ONLY',
                'email_enabled' => false,
                'whatsapp_enabled' => false,
                'lock_version' => 0,
            ];

            if ($proj === null) {
                $pgmei = [
                    'year' => $year,
                    'calendar_year' => $year,
                    'debt_state' => PgmeiDebtState::Unverified->value,
                    'freshness_state' => 'OUTDATED',
                    'debt_count' => 0,
                    'items_count' => 0,
                    'total_cents' => 0,
                    'last_valid_query_at' => null,
                    'communication' => $comm,
                ];
            } else {
                $pgmei = $proj->toPortfolioArray($now);
                $pgmei['communication'] = $comm;
            }

            $map[$cid] = [
                'module_key' => 'simples_mei',
                'submodule' => 'PGMEI',
                'calendar_year' => $year,
                'period_key' => PgmeiYear::toPeriodKey($year),
                'pgmei' => $pgmei,
                'debt_state' => $pgmei['debt_state'],
                'freshness_state' => $pgmei['freshness_state'],
                'items_count' => $pgmei['items_count'] ?? $pgmei['debt_count'] ?? 0,
                'total_cents' => $pgmei['total_cents'],
                'last_valid_query_at' => $pgmei['last_valid_query_at'],
                'communication' => $comm,
                'links' => [
                    'history' => "/api/v1/fiscal/simples-mei/pgmei/clients/{$cid}/history",
                    'preferences' => "/api/v1/fiscal/simples-mei/pgmei/clients/{$cid}/communication-preference",
                    'preview' => "/api/v1/fiscal/simples-mei/pgmei/clients/{$cid}/communication-preview",
                    'tracking' => "/api/v1/fiscal/simples-mei/pgmei/clients/{$cid}/communications",
                    'consult' => '/api/v1/fiscal/simples-mei/pgmei/consult',
                ],
            ];
        }

        return $map;
    }

    /**
     * Histórico local por cliente/ano — sem SERPRO.
     *
     * @return array<string, mixed>
     */
    public function history(Office $office, Client $client, ?int $year = null): array
    {
        $this->assertClient($office, $client);

        $tz = is_string($office->timezone) && $office->timezone !== ''
            ? $office->timezone
            : 'America/Sao_Paulo';
        $year ??= (int) CarbonImmutable::now($tz)->year;
        $year = PgmeiYear::assertValid($year);

        $projection = PgmeiDebtProjection::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('calendar_year', $year)
            ->first();

        $observations = PgmeiDebtObservation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('calendar_year', $year)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $observationIds = $observations->pluck('id')->all();
        $itemsByObs = PgmeiDebtItem::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereIn('observation_id', $observationIds !== [] ? $observationIds : [0])
            ->orderBy('position')
            ->get()
            ->groupBy('observation_id');

        $latestItems = [];
        if ($projection?->last_valid_observation_id) {
            $latestItems = PgmeiDebtItem::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('observation_id', $projection->last_valid_observation_id)
                ->orderBy('position')
                ->get()
                ->map(static fn (PgmeiDebtItem $i): array => $i->toPublicArray())
                ->all();
        }

        // DAS já existentes na Central de Guias (somente leitura local).
        $guides = TaxGuide::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where(function ($q) use ($year): void {
                $q->where('competence_period_key', 'like', sprintf('%04d%%', $year))
                    ->orWhere('competence_period_key', 'like', sprintf('%04d-%%', $year));
            })
            ->where(function ($q): void {
                $q->whereIn('service_code', ['PGMEI', 'MEI'])
                    ->orWhere('system_code', 'INTEGRA_MEI')
                    ->orWhereNull('service_code');
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(static function (TaxGuide $g): array {
                return [
                    'id' => $g->id,
                    'period_key' => $g->competence_period_key,
                    'amount_cents' => $g->amount_cents,
                    'payment_status' => $g->payment_status?->value ?? null,
                    'due_at' => $g->due_at?->toIso8601String(),
                    'source' => 'tax_guides',
                ];
            })
            ->all();

        $history = $observations->map(function (PgmeiDebtObservation $obs) use ($itemsByObs): array {
            $items = $itemsByObs->get($obs->id, collect())
                ->map(static fn (PgmeiDebtItem $i): array => $i->toPublicArray())
                ->values()
                ->all();

            return $obs->toPublicArray() + ['items' => $items];
        })->values()->all();

        $current = $projection?->toPortfolioArray();
        if ($current !== null) {
            $current['communication'] = $this->communication->summary($office, $client);
        }

        return [
            'client_id' => $client->id,
            'client' => [
                'id' => $client->id,
                'legal_name' => $client->legal_name,
            ],
            'year' => $year,
            'calendar_year' => $year,
            'current' => $current,
            'projection' => $current,
            'items' => $latestItems,
            'observations' => $history,
            'history' => $history,
            'guides' => $guides,
            'provenance' => [
                'source' => 'local_projection',
                'serpro_called' => false,
            ],
            'source' => 'local',
        ];
    }

    /**
     * Consulta manual explícita e confirmada — valida lote antes de criar qualquer execução.
     *
     * @param  list<int>  $clientIds
     * @return list<array<string, mixed>>
     */
    public function enqueueManualConsult(
        Office $office,
        array $clientIds,
        int $year,
        bool $confirmed,
        ?int $actorUserId,
    ): array {
        if (! $confirmed) {
            throw new HttpException(422, 'Consulta manual PGMEI exige confirmed=true.');
        }

        $year = PgmeiYear::assertValid($year);
        $clientIds = array_values(array_unique(array_map('intval', $clientIds)));
        if ($clientIds === [] || count($clientIds) > self::MANUAL_BATCH_LIMIT) {
            throw new HttpException(
                422,
                'Lote deve conter entre 1 e '.self::MANUAL_BATCH_LIMIT.' clientes.'
            );
        }

        $clients = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereIn('id', $clientIds)
            ->get()
            ->keyBy('id');

        if ($clients->count() !== count($clientIds)) {
            throw new HttpException(422, 'Lote contém cliente inacessível ao escritório.');
        }

        // Valida o lote inteiro antes da primeira escrita (já feito acima); cria runs atomicamente.
        $models = DB::transaction(function () use ($office, $clientIds, $clients, $year, $actorUserId): array {
            $created = [];
            foreach ($clientIds as $clientId) {
                /** @var Client $client */
                $client = $clients->get($clientId);
                $run = $this->runs->enqueueManual(
                    office: $office,
                    client: $client,
                    systemCode: 'INTEGRA_MEI',
                    serviceCode: 'PGMEI',
                    operationCode: 'MONITOR',
                    competence: null,
                    actorId: $actorUserId,
                    correlationId: sprintf('pgmei-manual-%d-%d-%s', $year, $clientId, (string) Str::uuid()),
                    dispatch: false,
                );

                $progress = is_array($run->progress) ? $run->progress : [];
                $progress['ano_calendario'] = (string) $year;
                $progress['anoCalendario'] = (string) $year;
                $progress['period_key'] = PgmeiYear::toPeriodKey($year);
                $progress['pgmei_manual'] = true;
                $run->forceFill(['progress' => $progress])->save();
                $created[] = $run;
            }

            return $created;
        });

        $out = [];
        foreach ($models as $run) {
            ExecuteFiscalMonitoringRunJob::dispatch($run->id)
                ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
            if (method_exists($run, 'toPublicArray')) {
                $data = $run->toPublicArray();
                unset($data['office_id'], $data['idempotency_key']);
                $out[] = $data;
            } else {
                $out[] = [
                    'id' => $run->id,
                    'client_id' => $run->client_id,
                    'status' => $run->status?->value ?? (string) $run->status,
                    'service_code' => $run->service_code,
                    'operation_code' => $run->operation_code,
                    'progress' => $run->progress,
                ];
            }
        }

        return $out;
    }

    private function assertClient(Office $office, Client $client): void
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }
    }
}
