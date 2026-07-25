<?php

namespace App\Services\Fiscal\Guides;

use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\PagtowebPaymentCountObservation;
use App\Models\PagtowebPaymentCountProjection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PagtowebPaymentCountProjector
{
    /** @param array<string,mixed> $summary @return array{observation:PagtowebPaymentCountObservation,projection:PagtowebPaymentCountProjection,created:bool} */
    public function project(Office $office, Client $client, array $summary, ?int $sourceRunId, string $provenance, ?CarbonImmutable $observedAt = null): array
    {
        if ((int) $client->office_id !== (int) $office->id || ! isset($summary['payment_count'], $summary['filter_summary']) || ! is_int($summary['payment_count']) || $summary['payment_count'] < 0 || ! is_array($summary['filter_summary'])) {
            throw new RuntimeException('Resumo PAGTOWEB inválido para projeção.');
        }
        $observedAt ??= CarbonImmutable::now();
        $digest = hash('sha256', json_encode(['payment_count' => $summary['payment_count'], 'filter_summary' => $summary['filter_summary'], 'source_provenance' => $provenance], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($office, $client, $summary, $sourceRunId, $provenance, $observedAt, $digest): array {
            if ($sourceRunId !== null && ! FiscalMonitoringRun::query()->withoutGlobalScopes()->whereKey($sourceRunId)->where('office_id', $office->id)->where('client_id', $client->id)->exists()) {
                throw new RuntimeException('Execução de origem PAGTOWEB inválida.');
            }
            $observation = PagtowebPaymentCountObservation::query()->withoutGlobalScopes()->where('office_id', $office->id)->where('client_id', $client->id)->where('digest', $digest)->lockForUpdate()->first();
            $created = $observation === null;
            $observation ??= PagtowebPaymentCountObservation::query()->create(['office_id' => $office->id, 'client_id' => $client->id, ...$summary, 'digest' => $digest, 'observed_at' => $observedAt, 'source_run_id' => $sourceRunId, 'source_provenance' => $provenance, 'created_at' => $observedAt]);
            $projection = PagtowebPaymentCountProjection::query()->withoutGlobalScopes()->where('office_id', $office->id)->where('client_id', $client->id)->lockForUpdate()->first();
            $data = [...$summary, 'last_valid_query_at' => $observedAt, 'last_observation_id' => $observation->id, 'last_run_id' => $sourceRunId, 'source_provenance' => $provenance];
            $projection = $projection === null ? PagtowebPaymentCountProjection::query()->create(['office_id' => $office->id, 'client_id' => $client->id, ...$data]) : tap($projection, static fn (PagtowebPaymentCountProjection $item) => $item->forceFill($data)->save());

            return ['observation' => $observation->refresh(), 'projection' => $projection->refresh(), 'created' => $created];
        });
    }
}
