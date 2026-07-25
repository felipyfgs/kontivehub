<?php

namespace App\Services\Fiscal\SimplesMei\Pgmei;

use App\Enums\PgmeiDebtState;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\PgmeiDebtItem;
use App\Models\PgmeiDebtObservation;
use App\Models\PgmeiDebtProjection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Projeta resposta produtiva válida de DIVIDAATIVA24 de forma atômica e idempotente.
 * Falhas/simulações/ambiguidade NÃO devem chamar este projector.
 */
final class PgmeiDebtProjector
{
    /**
     * @param  array{
     *   calendar_year: int,
     *   items: list<array{
     *     periodo_apuracao: ?string,
     *     tributo: ?string,
     *     amount_cents: int,
     *     ente_federado: ?string,
     *     situacao_debito: ?string
     *   }>,
     *   items_count: int,
     *   total_cents: int,
     *   digest: string
     * }  $decoded
     * @return array{observation: PgmeiDebtObservation, projection: PgmeiDebtProjection, created: bool}
     */
    public function projectValid(
        Office $office,
        Client $client,
        array $decoded,
        ?int $sourceRunId,
        ?CarbonImmutable $observedAt = null,
        ?int $sourceSnapshotId = null,
    ): array {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório da projeção PGMEI.');
        }

        $year = PgmeiYear::assertValid($decoded['calendar_year']);
        $observedAt ??= CarbonImmutable::now();
        $digest = (string) $decoded['digest'];
        $items = $decoded['items'];
        $count = (int) $decoded['items_count'];
        $total = (int) $decoded['total_cents'];
        $state = $count > 0 ? PgmeiDebtState::HasActiveDebt : PgmeiDebtState::NoActiveDebt;

        return DB::transaction(function () use (
            $office,
            $client,
            $year,
            $digest,
            $items,
            $count,
            $total,
            $state,
            $sourceRunId,
            $sourceSnapshotId,
            $observedAt,
        ): array {
            if (
                $sourceRunId !== null
                && ! FiscalMonitoringRun::query()
                    ->withoutGlobalScopes()
                    ->whereKey($sourceRunId)
                    ->where('office_id', $office->id)
                    ->where('client_id', $client->id)
                    ->exists()
            ) {
                throw new RuntimeException('Execução de origem inválida para a projeção PGMEI.');
            }

            $existingQuery = PgmeiDebtObservation::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->where('calendar_year', $year)
                ->where('digest', $digest)
                ->lockForUpdate();

            if ($sourceRunId !== null) {
                $byRun = PgmeiDebtObservation::query()
                    ->withoutGlobalScopes()
                    ->where('office_id', $office->id)
                    ->where('client_id', $client->id)
                    ->where('calendar_year', $year)
                    ->where('source_run_id', $sourceRunId)
                    ->lockForUpdate()
                    ->first();
                if ($byRun !== null) {
                    $existing = $byRun;
                    if (! hash_equals((string) $byRun->digest, $digest)) {
                        throw new RuntimeException('Replay PGMEI divergente para a mesma execução.');
                    }
                } else {
                    $existing = null;
                }
            } else {
                $existing = $existingQuery->first();
            }

            $created = false;
            if ($existing === null) {
                $observation = PgmeiDebtObservation::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $client->id,
                    'calendar_year' => $year,
                    'debt_state' => $state->value,
                    'digest' => $digest,
                    'items_count' => $count,
                    'total_cents' => $total,
                    'observed_at' => $observedAt,
                    'source_run_id' => $sourceRunId,
                    'source_snapshot_id' => $sourceSnapshotId,
                    'metadata' => null,
                    'created_at' => $observedAt,
                ]);

                foreach ($items as $position => $item) {
                    $pa = (string) ($item['periodo_apuracao'] ?? '');
                    $tributo = (string) ($item['tributo'] ?? '—');
                    $ente = (string) ($item['ente_federado'] ?? '—');
                    $situacao = (string) ($item['situacao_debito'] ?? '—');
                    $logical = hash('sha256', implode('|', [
                        $pa,
                        $tributo,
                        (string) $item['amount_cents'],
                        $ente,
                        $situacao,
                        (string) $position,
                    ]));

                    PgmeiDebtItem::query()->create([
                        'office_id' => $office->id,
                        'client_id' => $client->id,
                        'observation_id' => $observation->id,
                        'position' => $position,
                        'logical_key' => substr($logical, 0, 64),
                        'periodo_apuracao' => $pa !== '' ? $pa : sprintf('%04d01', $year),
                        'tributo' => mb_substr($tributo, 0, 120),
                        'amount_cents' => $item['amount_cents'],
                        'ente_federado' => mb_substr($ente, 0, 120),
                        'situacao_debito' => mb_substr($situacao, 0, 255),
                        'created_at' => $observedAt,
                    ]);
                }
                $created = true;
            } else {
                $observation = $existing;
            }

            $projection = PgmeiDebtProjection::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->where('calendar_year', $year)
                ->lockForUpdate()
                ->first();

            $payload = [
                'debt_state' => $state->value,
                'items_count' => $count,
                'total_cents' => $total,
                'last_valid_query_at' => $observedAt,
                'last_valid_observation_id' => $observation->id,
                'last_valid_run_id' => $sourceRunId,
                'last_valid_snapshot_id' => $sourceSnapshotId,
            ];

            if ($projection === null) {
                $projection = PgmeiDebtProjection::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $client->id,
                    'calendar_year' => $year,
                    ...$payload,
                ]);
            } else {
                $projection->forceFill($payload)->save();
            }

            return [
                'observation' => $observation->refresh(),
                'projection' => $projection->refresh(),
                'created' => $created,
            ];
        });
    }
}
