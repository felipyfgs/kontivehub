<?php

namespace App\Jobs;

use App\Contracts\SefazCteDistDfeClient;
use App\Enums\CaptureChannel;
use App\Enums\CredentialStatus;
use App\Enums\SyncCursorStatus;
use App\Exceptions\Adn\AdnPermanentException;
use App\Exceptions\Adn\AdnRetryableException;
use App\Exceptions\Adn\DocumentDecodeException;
use App\Models\ChannelSyncCursor;
use App\Models\ChannelSyncCursorTransition;
use App\Models\ClientCredential;
use App\Services\Certificates\CredentialService;
use App\Services\Operations\OperationsMetrics;
use App\Services\Operations\StructuredLogger;
use App\Services\Sefaz\CteDistDfePageProcessor;
use App\Support\LogSanitizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sincroniza DistDFe CT-e para um channel_sync_cursor (flag sefaz.cte_enabled).
 */
class SyncSefazCteDistDfeJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries = 1;

    public function __construct(
        public int $channelSyncCursorId,
        public string $trigger = 'SCHEDULED',
    ) {
        $this->timeout = max(60, (int) config('sefaz.job_timeout_seconds', 900));
        $this->onQueue((string) config('sefaz.queues.cte', 'sync-sefaz-cte'));
    }

    public function handle(
        SefazCteDistDfeClient $client,
        CteDistDfePageProcessor $processor,
        CredentialService $credentials,
        StructuredLogger $logger,
        OperationsMetrics $metrics,
    ): void {
        if (! config('sefaz.cte_enabled')) {
            return;
        }

        $cursor = ChannelSyncCursor::query()
            ->with(['establishment.client'])
            ->find($this->channelSyncCursorId);

        if ($cursor === null || $cursor->channel !== CaptureChannel::CteDistDfe) {
            return;
        }

        $lock = Cache::lock(
            'sefaz:cte:est:'.$cursor->establishment_id,
            (int) config('sefaz.lock_ttl_seconds', 960)
        );
        if (! $lock->get()) {
            return;
        }

        $owner = (string) Str::uuid();
        $started = hrtime(true);
        $totalQuarantine = 0;

        try {
            $fromStatus = $cursor->status?->value;
            $cursor->status = SyncCursorStatus::Running;
            $cursor->locked_at = now();
            $cursor->lock_owner = $owner;
            $cursor->attempts = (int) $cursor->attempts + 1;
            $cursor->save();
            ChannelSyncCursorTransition::record(
                $cursor,
                'job_start',
                $fromStatus,
                SyncCursorStatus::Running->value,
                metadata: ['trigger' => $this->trigger],
            );

            $establishment = $cursor->establishment;
            $clientModel = $establishment?->client;
            if (! $establishment || ! $clientModel) {
                throw new AdnPermanentException('Estabelecimento/cliente ausente para CT-e DistDFe.');
            }

            $credential = ClientCredential::query()
                ->where('client_id', $clientModel->id)
                ->where('status', CredentialStatus::Active)
                ->first();
            if (! $credential) {
                throw new AdnPermanentException('Credencial A1 ativa ausente para CT-e DistDFe.');
            }

            $material = $credentials->loadPfxMaterial($credential);
            if ($material === null) {
                throw new AdnPermanentException('Não foi possível materializar A1 para CT-e DistDFe.');
            }
            $cUf = $this->resolveUfAutor($establishment);
            $maxPages = (int) config('sefaz.max_pages_per_job', 12);
            $sleep = (float) config('sefaz.page_sleep_seconds', 2);
            $pages = 0;
            $totalDocs = 0;
            $lastCstat = null;

            while ($pages < $maxPages) {
                $page = $client->distByNsu(
                    $material,
                    $establishment->cnpj,
                    (int) $cursor->last_nsu,
                    $cUf,
                );
                $lastCstat = $page->cStat;

                $result = $processor->process($cursor, $establishment, $page);
                $totalDocs += $result['documents'];
                $totalQuarantine += $result['quarantined'];
                $cursor->refresh();
                $pages++;

                if ($page->isAbuse() || $page->isAuthError() || $page->isEndOfQueue() || $page->isEmpty()) {
                    break;
                }

                if ($pages < $maxPages && $sleep > 0) {
                    usleep((int) ($sleep * 1_000_000));
                }
            }

            if ($cursor->status === SyncCursorStatus::Running) {
                $cursor->status = SyncCursorStatus::Idle;
                $cursor->locked_at = null;
                $cursor->lock_owner = null;
                if ($cursor->next_sync_at === null || $cursor->next_sync_at->isPast()) {
                    $stillBehind = $cursor->max_nsu_seen !== null
                        && (int) $cursor->last_nsu < (int) $cursor->max_nsu_seen;
                    $cursor->next_sync_at = $stillBehind
                        ? now()->addSeconds(30)
                        : now()->addHours((float) config('sefaz.quiet_hours_after_empty', 1));
                }
                $cursor->save();
            }

            $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $logger->info('sefaz.cte.job.done', [
                'cursor_id' => $cursor->id,
                'pages' => $pages,
                'documents' => $totalDocs,
                'quarantined' => $totalQuarantine,
                'last_nsu' => $cursor->last_nsu,
                'cstat' => $lastCstat,
                'trigger' => $this->trigger,
            ], $cursor->office_id);
            $metrics->increment('cte.sync.pages', $pages, [
                'channel' => CaptureChannel::CteDistDfe->value,
                'stream' => 'client',
            ]);
            $metrics->increment('cte.documents', $totalDocs, [
                'channel' => CaptureChannel::CteDistDfe->value,
                'result' => 'sync',
            ]);
            if ($totalQuarantine > 0) {
                $metrics->increment('cte.quarantine', $totalQuarantine, [
                    'channel' => CaptureChannel::CteDistDfe->value,
                ]);
            }
            if ($lastCstat !== null) {
                $metrics->increment('cte.cstat', 1, [
                    'channel' => CaptureChannel::CteDistDfe->value,
                    'cstat' => $lastCstat,
                ]);
            }
            $metrics->observeLatency('cte.sync.latency_ms', $latencyMs, [
                'channel' => CaptureChannel::CteDistDfe->value,
                'stream' => 'client',
            ]);
            ChannelSyncCursorTransition::record(
                $cursor,
                'job_done',
                SyncCursorStatus::Running->value,
                $cursor->status?->value,
                metadata: [
                    'pages' => $pages,
                    'documents' => $totalDocs,
                    'cstat' => $lastCstat,
                ],
            );
        } catch (DocumentDecodeException|AdnPermanentException $e) {
            $this->failCursor($cursor, $e->getMessage(), permanent: true, logger: $logger, metrics: $metrics);
        } catch (AdnRetryableException $e) {
            $this->failCursor($cursor, $e->getMessage(), permanent: false, logger: $logger, metrics: $metrics);
        } catch (Throwable $e) {
            $this->failCursor(
                $cursor,
                'Falha CT-e DistDFe: '.mb_substr($e->getMessage(), 0, 200),
                permanent: false,
                logger: $logger,
                metrics: $metrics,
            );
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function failCursor(
        ?ChannelSyncCursor $cursor,
        string $message,
        bool $permanent,
        ?StructuredLogger $logger = null,
        ?OperationsMetrics $metrics = null,
    ): void {
        if (! $cursor) {
            return;
        }
        $from = $cursor->status?->value;
        $cursor->refresh();
        $cursor->last_error = LogSanitizer::scrubString($message);
        $cursor->status = $permanent ? SyncCursorStatus::Blocked : SyncCursorStatus::Error;
        $cursor->locked_at = null;
        $cursor->lock_owner = null;
        $cursor->next_sync_at = now()->addMinutes($permanent ? 60 : 15);
        $cursor->save();

        ChannelSyncCursorTransition::record(
            $cursor,
            $permanent ? 'job_blocked' : 'job_error',
            $from,
            $cursor->status->value,
            metadata: ['permanent' => $permanent],
        );
        $logger?->error('sefaz.cte.job.failed', [
            'cursor_id' => $cursor->id,
            'permanent' => $permanent,
            'status' => $cursor->status->value,
        ], $cursor->office_id);
        $metrics?->increment('cte.sync', 1, [
            'channel' => CaptureChannel::CteDistDfe->value,
            'result' => $permanent ? 'blocked' : 'error',
        ]);
    }

    private function resolveUfAutor($establishment): string
    {
        $state = $establishment->address_state
            ?? (is_array($establishment->address ?? null) ? ($establishment->address['state'] ?? $establishment->address['uf'] ?? null) : null)
            ?? null;
        $map = [
            'AC' => '12', 'AL' => '27', 'AP' => '16', 'AM' => '13', 'BA' => '29',
            'CE' => '23', 'DF' => '53', 'ES' => '32', 'GO' => '52', 'MA' => '21',
            'MT' => '51', 'MS' => '50', 'MG' => '31', 'PA' => '15', 'PB' => '25',
            'PR' => '41', 'PE' => '26', 'PI' => '22', 'RJ' => '33', 'RN' => '24',
            'RS' => '43', 'RO' => '11', 'RR' => '14', 'SC' => '42', 'SP' => '35',
            'SE' => '28', 'TO' => '17',
        ];
        if (is_string($state) && isset($map[strtoupper($state)])) {
            return $map[strtoupper($state)];
        }

        return (string) config('sefaz.default_cuf_autor', '35');
    }
}
