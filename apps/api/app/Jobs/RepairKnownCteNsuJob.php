<?php

namespace App\Jobs;

use App\Contracts\SefazCteDistDfeClient;
use App\Enums\CaptureChannel;
use App\Enums\CredentialStatus;
use App\Enums\SyncCursorStatus;
use App\Models\ChannelSyncCursor;
use App\Models\ChannelSyncCursorTransition;
use App\Models\ClientCredential;
use App\Services\Certificates\CredentialService;
use App\Services\Operations\OperationsMetrics;
use App\Services\Operations\StructuredLogger;
use App\Services\Sefaz\CteDistDfePageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Reparo pontual por consNSU conhecido; nunca descobre NSU nem move o cursor sequencial. */
class RepairKnownCteNsuJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public int $channelSyncCursorId,
        public int $knownNsu,
        public string $correlationId,
    ) {
        $this->onQueue((string) config('sefaz.queues.cte', 'sync-sefaz-cte'));
    }

    public function handle(
        SefazCteDistDfeClient $client,
        CteDistDfePageProcessor $processor,
        CredentialService $credentials,
        StructuredLogger $logger,
        OperationsMetrics $metrics,
    ): void {
        if (! config('sefaz.cte_enabled') || $this->knownNsu < 1) {
            return;
        }

        $cursor = ChannelSyncCursor::query()
            ->with(['establishment.client'])
            ->find($this->channelSyncCursorId);
        if ($cursor === null || $cursor->channel !== CaptureChannel::CteDistDfe) {
            return;
        }

        // O mesmo gate vale na API, comando e worker: nunca contornar quiet/circuito.
        if ($cursor->status === SyncCursorStatus::Blocked
            || ($cursor->next_sync_at?->isFuture() ?? false)) {
            $reason = $cursor->status === SyncCursorStatus::Blocked ? 'circuit_open' : 'quiet_active';
            $logger->warning('sefaz.cte.repair.denied', [
                'cursor_id' => $cursor->id,
                'nsu' => $this->knownNsu,
                'correlation_id' => $this->correlationId,
                'reason' => $reason,
            ], $cursor->office_id);
            $metrics->increment('cte.repair', 1, [
                'channel' => CaptureChannel::CteDistDfe->value,
                'result' => 'denied',
                'outcome' => $reason,
            ]);

            return;
        }

        $lock = Cache::lock('sefaz:cte:est:'.$cursor->establishment_id, (int) config('sefaz.lock_ttl_seconds', 960));
        if (! $lock->get()) {
            $logger->info('sefaz.cte.repair.lock_busy', [
                'cursor_id' => $cursor->id,
                'correlation_id' => $this->correlationId,
            ], $cursor->office_id);

            return;
        }

        $started = hrtime(true);

        try {
            $establishment = $cursor->establishment;
            $clientModel = $establishment?->client;
            if ($establishment === null || $clientModel === null) {
                return;
            }

            $credential = ClientCredential::query()
                ->where('office_id', $cursor->office_id)
                ->where('client_id', $clientModel->id)
                ->where('status', CredentialStatus::Active)
                ->first();
            $material = $credential ? $credentials->loadPfxMaterial($credential) : null;
            if ($material === null) {
                $logger->warning('sefaz.cte.repair.failed', [
                    'cursor_id' => $cursor->id,
                    'nsu' => $this->knownNsu,
                    'correlation_id' => $this->correlationId,
                    'reason' => 'active_a1_unavailable',
                ], $cursor->office_id);
                $metrics->increment('cte.repair', 1, [
                    'channel' => CaptureChannel::CteDistDfe->value,
                    'result' => 'failed',
                    'outcome' => 'a1_missing',
                ]);

                return;
            }

            $page = $client->findByNsu(
                $material,
                $establishment->cnpj,
                $this->knownNsu,
                $this->resolveUfAutor($establishment->address_state ?? data_get($establishment->address, 'state')),
            );
            $before = (int) $cursor->last_nsu;
            $fromStatus = $cursor->status?->value;
            $result = $processor->processKnownNsuRepair($cursor, $establishment, $page);
            $cursor->refresh();

            ChannelSyncCursorTransition::record(
                $cursor,
                'cons_nsu_repair',
                $fromStatus,
                $cursor->status?->value,
                $this->correlationId,
                [
                    'nsu' => $this->knownNsu,
                    'documents' => $result['documents'],
                    'quarantined' => $result['quarantined'],
                    'cursor_unchanged' => $before === (int) $cursor->last_nsu,
                    'cstat' => $page->cStat,
                ],
            );

            $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $logger->info('sefaz.cte.repair.done', [
                'cursor_id' => $cursor->id,
                'nsu' => $this->knownNsu,
                'correlation_id' => $this->correlationId,
                'documents' => $result['documents'],
                'quarantined' => $result['quarantined'],
                'cursor_unchanged' => $before === (int) $cursor->last_nsu,
                'cstat' => $page->cStat,
            ], $cursor->office_id);
            $metrics->increment('cte.repair', 1, [
                'channel' => CaptureChannel::CteDistDfe->value,
                'result' => 'ok',
                'cstat' => $page->cStat,
            ]);
            $metrics->observeLatency('cte.repair.latency_ms', $latencyMs, [
                'channel' => CaptureChannel::CteDistDfe->value,
            ]);
            $metrics->increment('cte.documents', $result['documents'], [
                'channel' => CaptureChannel::CteDistDfe->value,
                'result' => 'repair',
            ]);
            if ($result['quarantined'] > 0) {
                $metrics->increment('cte.quarantine', $result['quarantined'], [
                    'channel' => CaptureChannel::CteDistDfe->value,
                ]);
            }
        } finally {
            $lock->release();
        }
    }

    private function resolveUfAutor(?string $state): string
    {
        $map = [
            'AC' => '12', 'AL' => '27', 'AP' => '16', 'AM' => '13', 'BA' => '29',
            'CE' => '23', 'DF' => '53', 'ES' => '32', 'GO' => '52', 'MA' => '21',
            'MT' => '51', 'MS' => '50', 'MG' => '31', 'PA' => '15', 'PB' => '25',
            'PR' => '41', 'PE' => '26', 'PI' => '22', 'RJ' => '33', 'RN' => '24',
            'RS' => '43', 'RO' => '11', 'RR' => '14', 'SC' => '42', 'SP' => '35',
            'SE' => '28', 'TO' => '17',
        ];

        return $map[strtoupper((string) $state)] ?? (string) config('sefaz.default_cuf_autor', '35');
    }
}
