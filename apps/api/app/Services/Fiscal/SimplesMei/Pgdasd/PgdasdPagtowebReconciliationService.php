<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\SerproEnvironment;
use App\Enums\TaxProxyPowerStatus;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\PgdasdOperation;
use App\Models\TaxProxyPower;
use App\Services\Fiscal\Guides\PagtowebPaymentListAdapter;
use App\Services\Fiscal\Guides\PagtowebPaymentListCodec;
use App\Services\FiscalMonitoring\FiscalIdempotency;
use App\Services\Integra\ClientProcuracaoSyncService;
use App\Support\FeatureFlags;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Enfileira lotes PAGTOWEB somente quando há cobertura produtiva e poder 00004. */
final class PgdasdPagtowebReconciliationService
{
    private const REQUIRED_POWER = '00004';

    public function __construct(
        private readonly PagtowebPaymentListCodec $codec,
        private readonly ClientProcuracaoSyncService $procuracoes,
    ) {}

    /** @return array{queued:int,documents:int,reason:string} */
    public function enqueueAfterProductiveMonitor(
        Office $office,
        Client $client,
        FiscalMonitoringRun $sourceRun,
    ): array {
        $provenance = $sourceRun->source_provenance instanceof BackedEnum
            ? $sourceRun->source_provenance->value
            : (string) $sourceRun->source_provenance;

        if ((int) $sourceRun->office_id !== (int) $office->id
            || (int) $sourceRun->client_id !== (int) $client->id
            || strtoupper((string) $sourceRun->service_code) !== 'PGDASD'
            || ! in_array(strtoupper((string) $sourceRun->operation_code), ['MONITOR', 'CONSULTAR_DECLARACAO'], true)
            || $sourceRun->result !== FiscalRunResult::Success
            || $provenance !== FiscalSourceProvenance::SerproReal->value
        ) {
            return ['queued' => 0, 'documents' => 0, 'reason' => 'SOURCE_NOT_PRODUCTIVE_PGDASD'];
        }

        return $this->enqueueForClient($office, $client, (int) $sourceRun->id);
    }

    /** @return array{queued:int,documents:int,reason:string} */
    public function enqueueForClient(
        Office $office,
        Client $client,
        ?int $sourceRunId = null,
        ?int $documentLimit = null,
    ): array {
        $eligibility = $this->eligibilityReason($office, $client);
        if ($eligibility !== null) {
            return ['queued' => 0, 'documents' => 0, 'reason' => $eligibility];
        }

        $ttl = max(60, (int) config(
            'fiscal_monitoring.pgdasd_pagtoweb_reconciliation.negative_ttl_seconds',
            86_400,
        ));
        $cutoff = CarbonImmutable::now()->subSeconds($ttl);
        $limit = max(1, $documentLimit ?? (int) config(
            'fiscal_monitoring.pgdasd_pagtoweb_reconciliation.backfill_max_documents',
            500,
        ));

        $documents = PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('kind', 'DAS')
            ->whereNotNull('das_number')
            ->where('das_number', '<>', '')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('pagtoweb_payment_status')
                    ->orWhere(function ($stale) use ($cutoff): void {
                        $stale->where('pagtoweb_payment_status', 'NOT_FOUND')
                            ->where(function ($verified) use ($cutoff): void {
                                $verified->whereNull('pagtoweb_verified_at')
                                    ->orWhere('pagtoweb_verified_at', '<', $cutoff);
                            });
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('das_number')
            ->filter(static fn (mixed $document): bool => is_string($document) && $document !== '')
            ->unique()
            ->values()
            ->all();

        if ($documents === []) {
            return ['queued' => 0, 'documents' => 0, 'reason' => 'NO_COVERAGE_GAPS'];
        }

        $batchSize = min(100, max(1, (int) config(
            'fiscal_monitoring.pgdasd_pagtoweb_reconciliation.max_documents_per_batch',
            100,
        )));
        $queued = 0;
        $documentCount = 0;
        foreach (array_chunk($documents, $batchSize) as $batch) {
            $created = $this->enqueueBatch($office, $client, $batch, $ttl, $sourceRunId);
            if ($created) {
                $queued++;
                $documentCount += count($batch);
            }
        }

        return [
            'queued' => $queued,
            'documents' => $documentCount,
            'reason' => $queued > 0 ? 'QUEUED' : 'ALREADY_QUEUED',
        ];
    }

    /**
     * @return array{clients:int,queued:int,documents:int}
     */
    public function backfill(?int $officeId = null, ?int $clientLimit = null, ?int $documentBudget = null): array
    {
        $ttl = max(60, (int) config(
            'fiscal_monitoring.pgdasd_pagtoweb_reconciliation.negative_ttl_seconds',
            86_400,
        ));
        $cutoff = CarbonImmutable::now()->subSeconds($ttl);
        $maxClients = min(
            max(1, $clientLimit ?? (int) config('fiscal_monitoring.pgdasd_pagtoweb_reconciliation.backfill_max_clients', 25)),
            max(1, (int) config('fiscal_monitoring.pgdasd_pagtoweb_reconciliation.backfill_max_clients', 25)),
        );
        $maxDocuments = min(
            max(1, $documentBudget ?? (int) config('fiscal_monitoring.pgdasd_pagtoweb_reconciliation.backfill_max_documents', 500)),
            max(1, (int) config('fiscal_monitoring.pgdasd_pagtoweb_reconciliation.backfill_max_documents', 500)),
        );

        $pairs = DB::table('pgdasd_operations as operations')
            ->join('clients', function ($join): void {
                $join->on('clients.id', '=', 'operations.client_id')
                    ->on('clients.office_id', '=', 'operations.office_id');
            })
            ->join('offices', 'offices.id', '=', 'operations.office_id')
            ->where('operations.kind', 'DAS')
            ->whereNotNull('operations.das_number')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('operations.pagtoweb_payment_status')
                    ->orWhere(function ($stale) use ($cutoff): void {
                        $stale->where('operations.pagtoweb_payment_status', 'NOT_FOUND')
                            ->where(function ($verified) use ($cutoff): void {
                                $verified->whereNull('operations.pagtoweb_verified_at')
                                    ->orWhere('operations.pagtoweb_verified_at', '<', $cutoff);
                            });
                    });
            })
            ->where('clients.is_active', true)
            ->where('offices.is_active', true)
            ->when($officeId !== null, fn ($query) => $query->where('operations.office_id', $officeId))
            ->orderBy('operations.office_id')
            ->orderBy('operations.client_id')
            ->distinct()
            ->limit($maxClients)
            ->get(['operations.office_id', 'operations.client_id']);

        $summary = ['clients' => 0, 'queued' => 0, 'documents' => 0];
        foreach ($pairs as $pair) {
            $remaining = $maxDocuments - $summary['documents'];
            if ($remaining <= 0) {
                break;
            }

            $office = Office::query()->find((int) $pair->office_id);
            $client = Client::query()
                ->withoutGlobalScopes()
                ->where('office_id', (int) $pair->office_id)
                ->whereKey((int) $pair->client_id)
                ->first();
            if ($office === null || $client === null) {
                continue;
            }

            $result = $this->enqueueForClient($office, $client, null, $remaining);
            $summary['clients']++;
            $summary['queued'] += $result['queued'];
            $summary['documents'] += $result['documents'];
        }

        return $summary;
    }

    private function eligibilityReason(Office $office, Client $client): ?string
    {
        if (! (bool) config('fiscal_monitoring.pgdasd_pagtoweb_reconciliation.enabled', false)) {
            return 'RECONCILIATION_DISABLED';
        }
        if ((bool) config('fiscal.kill_switch', false)
            || (bool) config('fiscal_monitoring.kill_switch', false)
            || FeatureFlags::isKillSwitchActive()
        ) {
            return 'KILL_SWITCH';
        }
        if ((int) $client->office_id !== (int) $office->id || ! $office->is_active || ! $client->is_active) {
            return 'TENANT_OR_CLIENT_INELIGIBLE';
        }
        if (strtoupper((string) config('serpro.default_environment', 'TRIAL')) !== SerproEnvironment::Production->value) {
            return 'PRODUCTION_REQUIRED';
        }
        if (! FeatureFlags::isModuleEnabled('guias', (int) $office->id)) {
            return 'GUIDES_UNAVAILABLE';
        }

        $gate = $this->procuracoes->gateForOperation(
            $office,
            $client,
            SerproEnvironment::Production,
            [self::REQUIRED_POWER],
            'REQUIRED_WHEN_REPRESENTING',
        );
        if (! $gate['allowed']) {
            return $gate['code'] ?? 'PROXY_POWER_MISSING';
        }

        $hasExactPower = TaxProxyPower::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('environment', SerproEnvironment::Production->value)
            ->where('power_code', self::REQUIRED_POWER)
            ->where('status', TaxProxyPowerStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('valid_to')->orWhere('valid_to', '>', now());
            })
            ->exists();

        return $hasExactPower ? null : 'PROXY_POWER_00004_MISSING';
    }

    /** @param list<string> $documents */
    private function enqueueBatch(
        Office $office,
        Client $client,
        array $documents,
        int $ttl,
        ?int $sourceRunId,
    ): bool {
        $normalized = $this->codec->normalizeFilters([
            'numero_documento_lista' => $documents,
            'page' => 1,
            'per_page' => min(100, count($documents)),
        ]);
        $digests = (array) ($normalized['filter_summary']['numero_documento_digests'] ?? []);
        sort($digests, SORT_STRING);
        $batchDigest = hash('sha256', implode('|', $digests));
        $ttlSlot = (string) intdiv(CarbonImmutable::now()->getTimestamp(), $ttl);
        $slot = 'pagto:'.substr($batchDigest, 0, 32).':'.$ttlSlot;
        $idempotencyKey = FiscalIdempotency::runKey(
            (int) $office->id,
            (int) $client->id,
            PagtowebPaymentListAdapter::SYSTEM,
            PagtowebPaymentListAdapter::SERVICE,
            PagtowebPaymentListAdapter::OPERATION,
            null,
            FiscalTrigger::Reconciliation,
            $slot,
        );

        $persistedFilters = $normalized['filter_summary'];
        unset($persistedFilters['numero_documento_digests']);
        $progress = [
            'pagtoweb_payment_list_filters' => $persistedFilters,
            'pagtoweb_payment_list_documents_encrypted' => $this->codec->encryptDocumentNumbers($documents),
            'pgdasd_pagtoweb_reconciliation' => [
                'source_run_id' => $sourceRunId,
                'batch_digest' => $batchDigest,
                'document_count' => count($documents),
            ],
        ];

        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->firstOrCreate(
            ['office_id' => $office->id, 'idempotency_key' => $idempotencyKey],
            [
                'client_id' => $client->id,
                'system_code' => PagtowebPaymentListAdapter::SYSTEM,
                'service_code' => PagtowebPaymentListAdapter::SERVICE,
                'operation_code' => PagtowebPaymentListAdapter::OPERATION,
                'operation_key' => PagtowebPaymentListAdapter::OPERATION_KEY,
                'trigger' => FiscalTrigger::Reconciliation,
                'status' => FiscalRunStatus::Queued,
                'situation' => FiscalSituation::Unknown,
                'coverage' => FiscalCoverage::Unknown,
                'mutability' => FiscalMutability::ReadOnly,
                'correlation_id' => sprintf(
                    'pgdasd-pagtoweb-%d-%d-%s-%s',
                    $office->id,
                    $client->id,
                    substr($batchDigest, 0, 16),
                    $ttlSlot,
                ),
                'progress' => $progress,
            ],
        );

        if (! $run->wasRecentlyCreated) {
            return false;
        }

        ExecuteFiscalMonitoringRunJob::dispatch((int) $run->id)
            ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return true;
    }
}
