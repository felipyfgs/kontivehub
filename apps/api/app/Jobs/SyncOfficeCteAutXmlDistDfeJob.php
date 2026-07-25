<?php

namespace App\Jobs;

use App\Contracts\SefazCteDistDfeClient;
use App\Enums\CaptureChannel;
use App\Enums\SyncCursorStatus;
use App\Exceptions\Adn\AdnPermanentException;
use App\Exceptions\Adn\AdnRetryableException;
use App\Exceptions\Adn\DocumentDecodeException;
use App\Models\OfficeDistributionCursor;
use App\Models\OfficeDistributionRun;
use App\Services\Certificates\OfficeCredentialResolver;
use App\Services\Operations\OperationsMetrics;
use App\Services\Operations\StructuredLogger;
use App\Services\Sefaz\OfficeCteAutXmlPageProcessor;
use App\Support\CteAutXmlFeature;
use App\Support\LogSanitizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sincroniza CTeDistribuicaoDFe como escritório (autXML) — flags sefaz.cte_enabled + sefaz.cte_autxml.enabled.
 * Reutiliza OfficeCredential (finalidade NFE_AUTXML_DISTDFE); nunca ClientCredential.
 */
class SyncOfficeCteAutXmlDistDfeJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries = 1;

    public function __construct(
        public int $officeDistributionCursorId,
        public string $trigger = 'SCHEDULED',
    ) {
        $this->timeout = max(60, (int) config('sefaz.cte_autxml.job_timeout_seconds', 900));
        $this->onQueue((string) config('sefaz.cte_autxml.queue', 'sync-sefaz-cte-autxml'));
    }

    public function handle(
        SefazCteDistDfeClient $client,
        OfficeCteAutXmlPageProcessor $processor,
        OfficeCredentialResolver $resolver,
        StructuredLogger $logger,
        OperationsMetrics $metrics,
    ): void {
        if (! CteAutXmlFeature::isGloballyEnabled()) {
            return;
        }

        $cursor = OfficeDistributionCursor::query()->find($this->officeDistributionCursorId);
        if ($cursor === null || $cursor->channel !== CaptureChannel::CteAutXmlDistDfe) {
            return;
        }

        if (! CteAutXmlFeature::isOfficeAllowed((int) $cursor->office_id)) {
            return;
        }

        if ($cursor->external_consumer_status === 'EXTERNAL_CONSUMER_CONFLICT') {
            $logger->warning('sefaz.cte_autxml.external_consumer_conflict', [
                'cursor_id' => $cursor->id,
            ], $cursor->office_id);
            $metrics->increment('cte.sync', 1, [
                'channel' => CaptureChannel::CteAutXmlDistDfe->value,
                'result' => 'external_consumer',
                'stream' => 'office',
            ]);

            return;
        }

        $lockKey = 'sefaz:cte_autxml:root:'.$cursor->office_id.':'.$cursor->interested_root_cnpj.':'.$cursor->environment;
        $lock = Cache::lock($lockKey, (int) config('sefaz.cte_autxml.lock_ttl_seconds', 960));
        if (! $lock->get()) {
            return;
        }

        $owner = (string) Str::uuid();
        $run = null;

        try {
            $cursor->status = SyncCursorStatus::Running;
            $cursor->locked_at = now();
            $cursor->lock_owner = $owner;
            $cursor->attempts = (int) $cursor->attempts + 1;
            $cursor->save();

            $run = OfficeDistributionRun::query()->create([
                'office_id' => $cursor->office_id,
                'office_distribution_cursor_id' => $cursor->id,
                'status' => 'RUNNING',
                'trigger' => $this->trigger,
                'from_nsu' => (int) $cursor->last_nsu,
                'to_nsu' => (int) $cursor->last_nsu,
                'attempts' => 1,
                'started_at' => now(),
            ]);

            $resolved = $resolver->resolveForAutXml((int) $cursor->office_id);
            $material = $resolved['material'];
            $identityCnpj = strtoupper($resolved['identity']->cnpj);
            $queryCnpj = strtoupper($cursor->query_cnpj);

            if (substr($identityCnpj, 0, 8) !== substr($queryCnpj, 0, 8)) {
                throw new AdnPermanentException('CNPJ-base do cursor CT-e autXML diverge da identidade do escritório.');
            }

            $maxPages = (int) config('sefaz.cte_autxml.max_pages_per_job', 20);
            $sleep = (float) config('sefaz.cte_autxml.page_sleep_seconds', 2);
            $pages = 0;
            $totalDocs = 0;
            $totalQuarantine = 0;
            $cUf = '91';

            while ($pages < $maxPages) {
                $page = $client->distByLastNsu(
                    $material,
                    $cursor->query_cnpj,
                    (int) $cursor->last_nsu,
                    $cUf,
                );

                $result = $processor->process($cursor, $page, $run);
                $totalDocs += $result['documents'];
                $totalQuarantine += $result['quarantined'];
                $cursor->refresh();
                $pages++;

                if ($run) {
                    $run->pages_processed = $pages;
                    $run->save();
                }

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
                        : now()->addHours((float) config('sefaz.cte_autxml.quiet_hours_after_empty', 1));
                }
                $cursor->save();
            }

            if ($run) {
                $run->status = 'COMPLETED';
                $run->to_nsu = (int) $cursor->last_nsu;
                $run->pages_processed = $pages;
                $run->documents_persisted = $totalDocs;
                $run->documents_quarantined = $totalQuarantine;
                $run->finished_at = now();
                $run->save();
            }

            $logger->info('sefaz.cte_autxml.job.done', [
                'cursor_id' => $cursor->id,
                'pages' => $pages,
                'documents' => $totalDocs,
                'quarantined' => $totalQuarantine,
                'last_nsu' => $cursor->last_nsu,
                'trigger' => $this->trigger,
            ], $cursor->office_id);
            $metrics->increment('cte.sync.pages', $pages, [
                'channel' => CaptureChannel::CteAutXmlDistDfe->value,
                'stream' => 'office',
            ]);
            $metrics->increment('cte.documents', $totalDocs, [
                'channel' => CaptureChannel::CteAutXmlDistDfe->value,
                'result' => 'sync',
            ]);
            if ($totalQuarantine > 0) {
                $metrics->increment('cte.quarantine', $totalQuarantine, [
                    'channel' => CaptureChannel::CteAutXmlDistDfe->value,
                ]);
            }
        } catch (DocumentDecodeException $e) {
            $cursor->refresh();
            $this->failCursor(
                $cursor,
                $run,
                $e->getMessage(),
                permanent: $cursor->status === SyncCursorStatus::Blocked,
            );
            $metrics->increment('cte.sync', 1, [
                'channel' => CaptureChannel::CteAutXmlDistDfe->value,
                'result' => 'decode_error',
            ]);
        } catch (AdnPermanentException $e) {
            $this->failCursor($cursor, $run, $e->getMessage(), permanent: true);
            $metrics->increment('cte.sync', 1, [
                'channel' => CaptureChannel::CteAutXmlDistDfe->value,
                'result' => 'blocked',
            ]);
        } catch (AdnRetryableException $e) {
            $this->failCursor($cursor, $run, $e->getMessage(), permanent: false);
            $metrics->increment('cte.sync', 1, [
                'channel' => CaptureChannel::CteAutXmlDistDfe->value,
                'result' => 'retryable',
            ]);
        } catch (Throwable $e) {
            $logger->error('sefaz.cte_autxml.job.error', [
                'cursor_id' => $this->officeDistributionCursorId,
            ], $cursor->office_id, $e);
            $this->failCursor($cursor, $run, 'Falha interna no job CT-e autXML.', permanent: false);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function failCursor(
        OfficeDistributionCursor $cursor,
        ?OfficeDistributionRun $run,
        string $message,
        bool $permanent,
    ): void {
        $cursor->status = $permanent ? SyncCursorStatus::Blocked : SyncCursorStatus::Idle;
        $safeMessage = LogSanitizer::scrubString($message);
        $cursor->last_error = $safeMessage;
        $cursor->locked_at = null;
        $cursor->lock_owner = null;
        $cursor->next_sync_at = now()->addHours(
            $permanent
                ? (float) config('sefaz.cte_autxml.circuit_breaker_hours', 1)
                : (float) config('sefaz.cte_autxml.quiet_hours_after_empty', 1)
        );
        $cursor->save();

        if ($run) {
            $run->status = 'FAILED';
            $run->error_message = $safeMessage;
            $run->finished_at = now();
            $run->save();
        }
    }
}
