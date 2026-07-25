<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Models\CcmeiRegistrationStatusObservation;
use App\Models\CcmeiRegistrationStatusProjection;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Persiste apenas o resumo sanitizado de CCMEISITCADASTRAL123. */
final class CcmeiRegistrationStatusProjector
{
    /**
     * @param  array{status:string,enquadrado_mei:bool,situation:string,count:int}  $summary
     * @return array{observation:CcmeiRegistrationStatusObservation,projection:CcmeiRegistrationStatusProjection,created:bool}
     */
    public function project(Office $office, Client $client, array $summary, ?int $sourceRunId, string $sourceProvenance, ?CarbonImmutable $observedAt = null): array
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório da projeção cadastral CCMEI.');
        }
        $status = trim($summary['status']);
        $situation = trim($summary['situation']);
        if ($status === '' || $situation === '' || $summary['count'] < 1) {
            throw new RuntimeException('Resumo cadastral CCMEI inválido para projeção.');
        }
        $observedAt ??= CarbonImmutable::now();
        $digest = hash('sha256', json_encode([
            'status' => $status, 'enquadrado_mei' => $summary['enquadrado_mei'],
            'situation' => $situation, 'count' => $summary['count'], 'source_provenance' => $sourceProvenance,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($office, $client, $summary, $status, $situation, $sourceRunId, $sourceProvenance, $observedAt, $digest): array {
            if ($sourceRunId !== null && ! FiscalMonitoringRun::query()->withoutGlobalScopes()
                ->whereKey($sourceRunId)->where('office_id', $office->id)->where('client_id', $client->id)->exists()) {
                throw new RuntimeException('Execução de origem inválida para a projeção cadastral CCMEI.');
            }
            $existing = CcmeiRegistrationStatusObservation::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->where('client_id', $client->id)->where('digest', $digest)->lockForUpdate()->first();
            $created = $existing === null;
            $observation = $existing ?? CcmeiRegistrationStatusObservation::query()->create([
                'office_id' => $office->id, 'client_id' => $client->id, 'status' => $status,
                'enquadrado_mei' => $summary['enquadrado_mei'], 'situation' => $situation, 'count' => $summary['count'],
                'digest' => $digest, 'observed_at' => $observedAt, 'source_run_id' => $sourceRunId,
                'source_provenance' => $sourceProvenance, 'created_at' => $observedAt,
            ]);
            $projection = CcmeiRegistrationStatusProjection::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->where('client_id', $client->id)->lockForUpdate()->first();
            $payload = [
                'status' => $status, 'enquadrado_mei' => $summary['enquadrado_mei'], 'situation' => $situation,
                'count' => $summary['count'], 'last_valid_query_at' => $observedAt, 'last_observation_id' => $observation->id,
                'last_run_id' => $sourceRunId, 'source_provenance' => $sourceProvenance,
            ];
            $projection = $projection === null
                ? CcmeiRegistrationStatusProjection::query()->create(['office_id' => $office->id, 'client_id' => $client->id, ...$payload])
                : tap($projection, static fn (CcmeiRegistrationStatusProjection $item) => $item->forceFill($payload)->save());

            return ['observation' => $observation->refresh(), 'projection' => $projection->refresh(), 'created' => $created];
        });
    }
}
