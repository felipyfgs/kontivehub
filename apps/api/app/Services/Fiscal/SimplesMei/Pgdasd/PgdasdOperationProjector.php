<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\Enums\PgdasdOperationKind;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\PgdasdOperation;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Services\Fiscal\Declarations\TaxObligationCatalogService;
use App\Services\Fiscal\Declarations\TaxObligationProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Projeta operações válidas do serviço 13 sem relacionar artificialmente declaração e DAS. */
final class PgdasdOperationProjector
{
    public function __construct(
        private readonly TaxObligationCatalogService $catalog,
        private readonly TaxObligationProjectionService $projections,
    ) {}

    /**
     * @param  array{periods:list<array<string,mixed>>,incomplete:bool}  $decoded
     * @return array{upserted:list<PgdasdOperation>,projections:array<string,TaxObligationProjection>,last_declaration:?PgdasdOperation}
     */
    public function projectFromDecoded(
        FiscalMonitoringRun $run,
        Office $office,
        Client $client,
        array $decoded,
    ): array {
        if ((bool) ($decoded['incomplete'] ?? true)) {
            throw new RuntimeException('Resposta PGDAS-D incompleta não pode ser projetada.');
        }
        if ((int) $client->office_id !== (int) $office->id || (int) $run->office_id !== (int) $office->id) {
            throw new RuntimeException('Contexto tenant inválido para projeção PGDAS-D.');
        }

        $definition = $this->definition();
        $upserted = [];
        $periodProjections = [];
        $observedAt = CarbonImmutable::now();

        DB::transaction(function () use (
            $decoded,
            $definition,
            $office,
            $client,
            $run,
            $observedAt,
            &$upserted,
            &$periodProjections,
        ): void {
            foreach ($decoded['periods'] as $period) {
                $periodKey = (string) $period['period_key'];
                $projection = $this->projections->project(
                    office: $office,
                    client: $client,
                    obligation: $definition,
                    periodKey: $periodKey,
                    competenceId: $run->competence?->period_key === $periodKey
                        ? $run->competence_id
                        : null,
                );
                $periodProjections[$periodKey] = $projection;

                foreach ($period['operations'] as $operation) {
                    $identity = [
                        'office_id' => $office->id,
                        'client_id' => $client->id,
                        'logical_key' => $operation['logical_key'],
                    ];
                    $model = PgdasdOperation::query()
                        ->withoutGlobalScopes()
                        ->where($identity)
                        ->lockForUpdate()
                        ->first();

                    $attributes = [
                        'projection_id' => $projection->id,
                        'kind' => $operation['kind'],
                        'period_key' => $periodKey,
                        'raw_operation_type' => $operation['raw_operation_type'],
                        'normalized_operation_type' => $operation['normalized_operation_type'],
                        'declaration_number' => $operation['declaration_number'],
                        'das_number' => $operation['das_number'],
                        'transmitted_at' => $operation['transmitted_at'],
                        'issued_at' => $operation['issued_at'],
                        'malha' => $operation['malha'],
                        'payment_located' => $operation['payment_located'],
                        'payment_observed_at' => $operation['payment_located'] !== null ? $observedAt : null,
                        'last_seen_at' => $observedAt,
                        'source_run_id' => $run->id,
                        'metadata' => [
                            'source_operation_key' => 'pgdasd.consdeclaracao',
                        ],
                    ];

                    if ($model === null) {
                        $model = PgdasdOperation::query()->create($identity + $attributes + [
                            'first_seen_at' => $observedAt,
                        ]);
                    } else {
                        $model->forceFill($attributes)->save();
                        $model->refresh();
                    }
                    $upserted[] = $model;
                }
            }
        });

        return [
            'upserted' => $upserted,
            'projections' => $periodProjections,
            'last_declaration' => $this->chooseLatestDeclaration($upserted),
        ];
    }

    /** @param list<PgdasdOperation> $operations */
    public function chooseLatestDeclaration(array $operations): ?PgdasdOperation
    {
        $declarations = array_values(array_filter(
            $operations,
            static fn (PgdasdOperation $operation): bool => $operation->operationKind() === PgdasdOperationKind::Declaration
                && $operation->transmitted_at !== null,
        ));
        usort($declarations, static function (PgdasdOperation $left, PgdasdOperation $right): int {
            $byTransmission = $right->transmitted_at <=> $left->transmitted_at;
            if ($byTransmission !== 0) {
                return $byTransmission;
            }

            return strcmp((string) $right->declaration_number, (string) $left->declaration_number);
        });

        return $declarations[0] ?? null;
    }

    public function latestDeclarationForPeriod(int $officeId, int $clientId, string $periodKey): ?PgdasdOperation
    {
        $operations = PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('client_id', $clientId)
            ->where('period_key', $periodKey)
            ->where('kind', PgdasdOperationKind::Declaration->value)
            ->get()
            ->all();

        return $this->chooseLatestDeclaration($operations);
    }

    public function ensureProjectionForPeriod(
        Office $office,
        Client $client,
        string $periodKey,
        ?int $competenceId = null,
    ): TaxObligationProjection {
        return $this->projections->project(
            office: $office,
            client: $client,
            obligation: $this->definition(),
            periodKey: $periodKey,
            competenceId: $competenceId,
        );
    }

    private function definition(): TaxObligationDefinition
    {
        $definition = $this->catalog->findByCode('PGDAS_D');
        if ($definition === null) {
            throw new RuntimeException('Obrigação PGDAS_D ausente do catálogo fiscal.');
        }

        return $definition;
    }
}
