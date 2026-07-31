<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\Enums\FiscalSourceProvenance;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\PagtowebPaymentListObservation;
use App\Models\Tenant;
use App\Services\Fiscal\Guides\PagtoWebPaymentListCodec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Reaplica evidência PAGTOWEB já persistida com digest canônico, sem live SERPRO.
 */
final class PgdasdPagtoWebEvidenceReapplyService
{
    public function __construct(
        private readonly PagtoWebPaymentListCodec $codec,
        private readonly PgdasdPagtoWebEvidenceService $evidence,
    ) {}

    /**
     * @return array{observations:int,paid:int,not_found:int,skipped:int}
     */
    public function reapply(?int $tenantId = null, ?int $clientId = null): array
    {
        Http::preventStrayRequests();

        $summary = ['observations' => 0, 'paid' => 0, 'not_found' => 0, 'skipped' => 0];

        $query = PagtowebPaymentListObservation::query()
            ->withoutGlobalScopes()
            ->where('source_provenance', FiscalSourceProvenance::SerproReal->value)
            ->orderBy('id');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        foreach ($query->cursor() as $observation) {
            $result = $this->reapplyObservation($observation);
            if ($result === null) {
                $summary['skipped']++;

                continue;
            }
            $summary['observations']++;
            $summary['paid'] += $result['paid'];
            $summary['not_found'] += $result['not_found'];
        }

        return $summary;
    }

    /**
     * @return array{paid:int,not_found:int}|null
     */
    private function reapplyObservation(PagtowebPaymentListObservation $observation): ?array
    {
        $tenant = Tenant::query()->find($observation->tenant_id);
        $client = Client::query()->withoutGlobalScopes()
            ->whereKey($observation->client_id)
            ->where('tenant_id', $observation->tenant_id)
            ->first();
        if ($tenant === null || $client === null) {
            return null;
        }

        $digests = $this->consultedDigests($observation);
        if ($digests === []) {
            return null;
        }

        $verifiedAt = $observation->observed_at instanceof CarbonImmutable
            ? $observation->observed_at
            : CarbonImmutable::parse((string) $observation->observed_at);

        return DB::transaction(function () use ($tenant, $client, $observation, $digests, $verifiedAt): array {
            return $this->evidence->apply(
                $tenant,
                $client,
                $observation,
                $digests,
                $observation->source_run_id !== null ? (int) $observation->source_run_id : null,
                $verifiedAt,
            );
        });
    }

    /**
     * @return list<string>
     */
    private function consultedDigests(PagtowebPaymentListObservation $observation): array
    {
        $documents = $this->documentsFromSourceRun($observation);
        if ($documents !== []) {
            return array_values(array_unique(array_map(
                $this->codec->documentDigest(...),
                $documents,
            )));
        }

        $recordedDigests = (array) ($observation->filter_summary['numero_documento_digests'] ?? []);

        return array_values(array_filter(
            $recordedDigests,
            static fn (mixed $digest): bool => is_string($digest)
                && preg_match('/^[a-f0-9]{64}$/', $digest) === 1,
        ));
    }

    /**
     * @return list<string>
     */
    private function documentsFromSourceRun(PagtowebPaymentListObservation $observation): array
    {
        if ($observation->source_run_id === null) {
            return [];
        }

        $run = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->whereKey($observation->source_run_id)
            ->where('tenant_id', $observation->tenant_id)
            ->where('client_id', $observation->client_id)
            ->first();
        if ($run === null) {
            return [];
        }

        $encrypted = $run->progress['pagtoweb_payment_list_documents_encrypted'] ?? null;
        if (! is_string($encrypted) || $encrypted === '') {
            return [];
        }

        try {
            return $this->codec->decryptDocumentNumbers($encrypted);
        } catch (\Throwable) {
            return [];
        }
    }
}
