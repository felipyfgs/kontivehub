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
use App\Models\PgdasdOperation;
use App\Models\TaxProxyPower;
use App\Models\Tenant;
use App\Services\Fiscal\Guides\PagtoWebPaymentListAdapter;
use App\Services\Fiscal\Guides\PagtoWebPaymentListCodec;
use App\Services\FiscalMonitoring\FiscalIdempotency;
use App\Services\Integra\ClientProcuracaoSyncService;
use App\Support\FeatureFlags;
use BackedEnum;
use Carbon\CarbonImmutable;

/** Enfileira lotes PAGTOWEB somente quando há cobertura produtiva e poder 00004. */
final class PgdasdPagtoWebReconciliationService
{
    private const REQUIRED_POWER = '00004';

    public function __construct(
        private readonly PagtoWebPaymentListCodec $codec,
        private readonly ClientProcuracaoSyncService $procuracoes,
    ) {}

    /** @return array{queued:int,documents:int,reason:string} */
    public function enqueueAfterProductiveMonitor(
        Tenant $tenant,
        Client $client,
        FiscalMonitoringRun $sourceRun,
    ): array {
        $provenance = $sourceRun->source_provenance instanceof BackedEnum
            ? $sourceRun->source_provenance->value
            : (string) $sourceRun->source_provenance;

        if ((int) $sourceRun->tenant_id !== (int) $tenant->id
            || (int) $sourceRun->client_id !== (int) $client->id
            || strtoupper((string) $sourceRun->service_code) !== 'PGDASD'
            || ! in_array(strtoupper((string) $sourceRun->operation_code), ['MONITOR', 'CONSULTAR_DECLARACAO'], true)
            || $sourceRun->result !== FiscalRunResult::Success
            || $provenance !== FiscalSourceProvenance::SerproReal->value
        ) {
            return ['queued' => 0, 'documents' => 0, 'reason' => 'SOURCE_NOT_PRODUCTIVE_PGDASD'];
        }

        return $this->enqueueForClient($tenant, $client, (int) $sourceRun->id);
    }

    /** @return array{queued:int,documents:int,reason:string} */
    public function enqueueForClient(
        Tenant $tenant,
        Client $client,
        ?int $sourceRunId = null,
        ?int $documentLimit = null,
    ): array {
        $eligibility = $this->eligibilityReason($tenant, $client);
        if ($eligibility !== null) {
            return ['queued' => 0, 'documents' => 0, 'reason' => $eligibility];
        }

        $ttl = max(60, (int) config(
            'fiscal_monitoring.pgdasd_pagtoweb_reconciliation.negative_ttl_seconds',
            86_400,
        ));
        $cutoff = CarbonImmutable::now()->subSeconds($ttl);
        $limit = max(1, $documentLimit ?? (int) config(
            'fiscal_monitoring.pgdasd_pagtoweb_reconciliation.max_documents_per_client',
            500,
        ));

        $documents = PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
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
            $created = $this->enqueueBatch($tenant, $client, $batch, $ttl, $sourceRunId);
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

    private function eligibilityReason(Tenant $tenant, Client $client): ?string
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
        if ((int) $client->tenant_id !== (int) $tenant->id || ! $tenant->is_active || ! $client->is_active) {
            return 'TENANT_OR_CLIENT_INELIGIBLE';
        }
        if (strtoupper((string) config('serpro.default_environment', 'TRIAL')) !== SerproEnvironment::Production->value) {
            return 'PRODUCTION_REQUIRED';
        }
        if (! FeatureFlags::isModuleEnabled('guides', (int) $tenant->id)) {
            return 'GUIDES_UNAVAILABLE';
        }

        $gate = $this->procuracoes->gateForOperation(
            $tenant,
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
            ->where('tenant_id', $tenant->id)
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
        Tenant $tenant,
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
            (int) $tenant->id,
            (int) $client->id,
            PagtoWebPaymentListAdapter::SYSTEM,
            PagtoWebPaymentListAdapter::SERVICE,
            PagtoWebPaymentListAdapter::OPERATION,
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
            ['tenant_id' => $tenant->id, 'idempotency_key' => $idempotencyKey],
            [
                'client_id' => $client->id,
                'system_code' => PagtoWebPaymentListAdapter::SYSTEM,
                'service_code' => PagtoWebPaymentListAdapter::SERVICE,
                'operation_code' => PagtoWebPaymentListAdapter::OPERATION,
                'operation_key' => PagtoWebPaymentListAdapter::OPERATION_KEY,
                'trigger' => FiscalTrigger::Reconciliation,
                'status' => FiscalRunStatus::Queued,
                'situation' => FiscalSituation::Unknown,
                'coverage' => FiscalCoverage::Unknown,
                'mutability' => FiscalMutability::ReadOnly,
                'correlation_id' => sprintf(
                    'pgdasd-pagtoweb-%d-%d-%s-%s',
                    $tenant->id,
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
