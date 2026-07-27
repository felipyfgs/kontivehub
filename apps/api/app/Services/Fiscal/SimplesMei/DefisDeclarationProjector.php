<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Models\Client;
use App\Models\DefisDeclarationObservation;
use App\Models\DefisDeclarationProjection;
use App\Models\FiscalMonitoringRun;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Persiste apenas os campos públicos permitidos da listagem DEFIS 142. */
final class DefisDeclarationProjector
{
    public function __construct(
        private readonly DefisDeclarationReferenceStore $references,
        private readonly DefisDeclarationsCodec $codec,
    ) {}

    public function projectFromResponse(
        Tenant $tenant,
        Client $client,
        mixed $dados,
        ?int $sourceRunId,
        string $sourceProvenance,
    ): void {
        $payload = is_string($dados) ? json_decode($dados, true) : $dados;
        $this->project($tenant, $client, $this->codec->decodeWithReferences(is_array($payload) ? $payload : []), $sourceRunId, $sourceProvenance);
    }

    /**
     * @param  list<array{calendar_year:int,type:string,transmitted_at:?string}>  $declarations
     */
    public function project(
        Tenant $tenant,
        Client $client,
        array $declarations,
        ?int $sourceRunId,
        string $sourceProvenance,
        ?CarbonImmutable $observedAt = null,
    ): void {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new RuntimeException('Cliente não pertence ao escritório da projeção DEFIS.');
        }
        $observedAt ??= CarbonImmutable::now();

        DB::transaction(function () use ($tenant, $client, $declarations, $sourceRunId, $sourceProvenance, $observedAt): void {
            if ($sourceRunId !== null && ! FiscalMonitoringRun::query()
                ->withoutGlobalScopes()
                ->whereKey($sourceRunId)
                ->where('tenant_id', $tenant->id)
                ->where('client_id', $client->id)
                ->exists()) {
                throw new RuntimeException('Execução de origem inválida para a projeção DEFIS.');
            }

            foreach ($declarations as $declaration) {
                $year = (int) ($declaration['calendar_year'] ?? 0);
                $type = (string) ($declaration['type'] ?? '');
                if ($year < 2000 || $year > 2100 || ! in_array($type, ['1', '2', '3', '4'], true)) {
                    throw new RuntimeException('Declaração DEFIS inválida para projeção.');
                }
                $digest = hash('sha256', json_encode([
                    'calendar_year' => $year,
                    'declaration_type' => $type,
                    'source_provenance' => $sourceProvenance,
                ], JSON_THROW_ON_ERROR));
                $observation = DefisDeclarationObservation::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('client_id', $client->id)
                    ->where('digest', $digest)
                    ->lockForUpdate()
                    ->first();
                if ($observation === null) {
                    $observation = DefisDeclarationObservation::query()->create([
                        'tenant_id' => $tenant->id,
                        'client_id' => $client->id,
                        'calendar_year' => $year,
                        'declaration_type' => $type,
                        'digest' => $digest,
                        'observed_at' => $observedAt,
                        'source_run_id' => $sourceRunId,
                        'source_provenance' => $sourceProvenance,
                        'created_at' => $observedAt,
                    ]);
                }

                $referenceId = null;
                if (is_string($declaration['id_defis'] ?? null)) {
                    $referenceId = $this->references->store($tenant, $client, $declaration['id_defis'], $sourceRunId, $sourceProvenance)->id;
                }

                DefisDeclarationProjection::query()->updateOrCreate([
                    'tenant_id' => $tenant->id,
                    'client_id' => $client->id,
                    'calendar_year' => $year,
                    'declaration_type' => $type,
                ], [
                    'last_observed_at' => $observedAt,
                    'last_observation_id' => $observation->id,
                    'last_run_id' => $sourceRunId,
                    'defis_declaration_reference_id' => $referenceId,
                    'source_provenance' => $sourceProvenance,
                ]);
            }
        });
    }
}
