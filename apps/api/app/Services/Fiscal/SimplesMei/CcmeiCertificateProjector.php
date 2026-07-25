<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Models\CcmeiCertificateObservation;
use App\Models\CcmeiCertificateProjection;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Persiste somente o resumo permitido de uma consulta CCMEI válida.
 */
final class CcmeiCertificateProjector
{
    /**
     * @param  array{status:string,situation:string}  $summary
     * @return array{observation:CcmeiCertificateObservation,projection:CcmeiCertificateProjection,created:bool}
     */
    public function project(
        Office $office,
        Client $client,
        array $summary,
        ?int $sourceRunId,
        string $sourceProvenance,
        ?CarbonImmutable $observedAt = null,
    ): array {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório da projeção CCMEI.');
        }

        $status = trim((string) $summary['status']);
        $situation = trim((string) $summary['situation']);
        if ($status === '' || $situation === '') {
            throw new RuntimeException('Resumo CCMEI inválido para projeção.');
        }

        $observedAt ??= CarbonImmutable::now();
        $digest = hash('sha256', json_encode([
            'status' => $status,
            'situation' => $situation,
            'source_provenance' => $sourceProvenance,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $office,
            $client,
            $status,
            $situation,
            $digest,
            $sourceRunId,
            $sourceProvenance,
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
                throw new RuntimeException('Execução de origem inválida para a projeção CCMEI.');
            }

            $existing = CcmeiCertificateObservation::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->where('digest', $digest)
                ->lockForUpdate()
                ->first();

            $created = $existing === null;
            $observation = $existing ?? CcmeiCertificateObservation::query()->create([
                'office_id' => $office->id,
                'client_id' => $client->id,
                'status' => $status,
                'situation' => $situation,
                'digest' => $digest,
                'observed_at' => $observedAt,
                'source_run_id' => $sourceRunId,
                'source_provenance' => $sourceProvenance,
                'created_at' => $observedAt,
            ]);

            $projection = CcmeiCertificateProjection::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->first();

            $payload = [
                'status' => $status,
                'situation' => $situation,
                'last_valid_query_at' => $observedAt,
                'last_observation_id' => $observation->id,
                'last_run_id' => $sourceRunId,
                'source_provenance' => $sourceProvenance,
            ];

            if ($projection === null) {
                $projection = CcmeiCertificateProjection::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $client->id,
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
