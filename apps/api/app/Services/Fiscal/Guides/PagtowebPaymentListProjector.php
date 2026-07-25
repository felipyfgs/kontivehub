<?php

namespace App\Services\Fiscal\Guides;

use App\Enums\FiscalSourceProvenance;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\PagtowebPaymentListItem;
use App\Models\PagtowebPaymentListObservation;
use App\Models\PagtowebPaymentListProjection;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPagtowebEvidenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PagtowebPaymentListProjector
{
    public function __construct(private readonly PgdasdPagtowebEvidenceService $pgdasdEvidence) {}

    /** @param list<array<string,mixed>> $items @param array<string,mixed> $filterSummary @return array{observation:PagtowebPaymentListObservation,created:bool} */
    public function project(Office $office, Client $client, array $items, array $filterSummary, ?int $sourceRunId, string $provenance, ?CarbonImmutable $observedAt = null): array
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente PAGTOWEB fora do escritório atual.');
        }
        $observedAt ??= CarbonImmutable::now();
        $digest = hash('sha256', json_encode(['items' => array_column($items, 'document_digest'), 'filters' => $filterSummary, 'source' => $provenance], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($office, $client, $items, $filterSummary, $sourceRunId, $provenance, $observedAt, $digest): array {
            if ($sourceRunId !== null && ! FiscalMonitoringRun::query()->withoutGlobalScopes()->whereKey($sourceRunId)->where('office_id', $office->id)->where('client_id', $client->id)->exists()) {
                throw new RuntimeException('Execução de origem PAGTOWEB inválida.');
            }
            $observation = PagtowebPaymentListObservation::query()->withoutGlobalScopes()->where('office_id', $office->id)->where('client_id', $client->id)->where('digest', $digest)->lockForUpdate()->first();
            $created = $observation === null;
            $observation ??= PagtowebPaymentListObservation::query()->create(['office_id' => $office->id, 'client_id' => $client->id, 'filter_summary' => $filterSummary, 'returned_count' => count($items), 'digest' => $digest, 'observed_at' => $observedAt, 'source_run_id' => $sourceRunId, 'source_provenance' => $provenance, 'created_at' => $observedAt]);
            if ($created) {
                foreach ($items as $item) {
                    PagtowebPaymentListItem::query()->create(['observation_id' => $observation->id, 'office_id' => $office->id, 'client_id' => $client->id, ...$item, 'created_at' => $observedAt]);
                }
            }
            $projection = PagtowebPaymentListProjection::query()->withoutGlobalScopes()->where('office_id', $office->id)->where('client_id', $client->id)->lockForUpdate()->first();
            $data = ['last_observation_id' => $observation->id, 'last_run_id' => $sourceRunId, 'last_valid_query_at' => $observedAt, 'source_provenance' => $provenance];
            $projection === null ? PagtowebPaymentListProjection::query()->create(['office_id' => $office->id, 'client_id' => $client->id, ...$data]) : $projection->forceFill($data)->save();

            if ($provenance === FiscalSourceProvenance::SerproReal->value) {
                $this->pgdasdEvidence->apply(
                    $office,
                    $client,
                    $observation,
                    (array) ($filterSummary['numero_documento_digests'] ?? []),
                    $sourceRunId,
                    $observedAt,
                );
            }

            return ['observation' => $observation->refresh(), 'created' => $created];
        });
    }
}
