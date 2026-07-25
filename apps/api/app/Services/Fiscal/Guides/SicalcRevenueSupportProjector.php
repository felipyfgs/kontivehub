<?php

namespace App\Services\Fiscal\Guides;

use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\SicalcRevenueSupportObservation;
use App\Models\SicalcRevenueSupportProjection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Persiste o metadado sanitizado da consulta SICALC 5.2, por cliente. */
final class SicalcRevenueSupportProjector
{
    /**
     * @param  array{revenue_code:string,description:string,extensions:list<array<string, array<string, bool|string>>>}  $summary
     * @return array{observation:SicalcRevenueSupportObservation,projection:SicalcRevenueSupportProjection,created:bool}
     */
    public function project(Office $office, Client $client, array $summary, ?int $sourceRunId, string $sourceProvenance, ?CarbonImmutable $observedAt = null): array
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório da projeção SICALC.');
        }
        if ($summary['revenue_code'] === '' || $summary['description'] === '' || $summary['extensions'] === []) {
            throw new RuntimeException('Resumo de receita SICALC inválido para projeção.');
        }
        $observedAt ??= CarbonImmutable::now();
        $digest = hash('sha256', json_encode([
            'revenue_code' => $summary['revenue_code'], 'description' => $summary['description'],
            'extensions' => $summary['extensions'], 'source_provenance' => $sourceProvenance,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($office, $client, $summary, $sourceRunId, $sourceProvenance, $observedAt, $digest): array {
            if ($sourceRunId !== null && ! FiscalMonitoringRun::query()->withoutGlobalScopes()
                ->whereKey($sourceRunId)->where('office_id', $office->id)->where('client_id', $client->id)->exists()) {
                throw new RuntimeException('Execução de origem inválida para a projeção SICALC.');
            }
            $existing = SicalcRevenueSupportObservation::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->where('client_id', $client->id)
                ->where('revenue_code', $summary['revenue_code'])->where('digest', $digest)->lockForUpdate()->first();
            $created = $existing === null;
            $observation = $existing ?? SicalcRevenueSupportObservation::query()->create([
                'office_id' => $office->id, 'client_id' => $client->id, ...$summary,
                'extension_count' => count($summary['extensions']), 'digest' => $digest, 'observed_at' => $observedAt,
                'source_run_id' => $sourceRunId, 'source_provenance' => $sourceProvenance, 'created_at' => $observedAt,
            ]);
            $projection = SicalcRevenueSupportProjection::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->where('client_id', $client->id)
                ->where('revenue_code', $summary['revenue_code'])->lockForUpdate()->first();
            $payload = [
                ...$summary, 'extension_count' => count($summary['extensions']), 'last_valid_query_at' => $observedAt,
                'last_observation_id' => $observation->id, 'last_run_id' => $sourceRunId, 'source_provenance' => $sourceProvenance,
            ];
            $projection = $projection === null
                ? SicalcRevenueSupportProjection::query()->create(['office_id' => $office->id, 'client_id' => $client->id, ...$payload])
                : tap($projection, static fn (SicalcRevenueSupportProjection $item) => $item->forceFill($payload)->save());

            return ['observation' => $observation->refresh(), 'projection' => $projection->refresh(), 'created' => $created];
        });
    }
}
