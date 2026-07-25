<?php

namespace App\Jobs;

use App\Contracts\SefazDistDfeClient;
use App\Enums\CaptureChannel;
use App\Enums\SyncCursorStatus;
use App\Exceptions\Adn\AdnPermanentException;
use App\Exceptions\Adn\AdnRetryableException;
use App\Exceptions\Adn\DocumentDecodeException;
use App\Models\OfficeDistributionCursor;
use App\Models\OfficeDistributionRun;
use App\Services\Certificates\OfficeCredentialResolver;
use App\Services\Sefaz\OfficeAutXmlPageProcessor;
use App\Support\AutXmlFeature;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sincroniza DistDFe NF-e como escritório (autXML) — flag sefaz.autxml.enabled.
 * Isolado do SyncSefazDistDfeJob (cliente): credencial, cursor e classificação próprios.
 */
class SyncOfficeAutXmlDistDfeJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries = 1;

    public function __construct(
        public int $officeDistributionCursorId,
        public string $trigger = 'SCHEDULED',
    ) {
        $this->timeout = max(60, (int) config('sefaz.autxml.job_timeout_seconds', 900));
        $this->onQueue((string) config('sefaz.autxml.queue', 'sync-sefaz-autxml'));
    }

    public function handle(
        SefazDistDfeClient $client,
        OfficeAutXmlPageProcessor $processor,
        OfficeCredentialResolver $resolver,
    ): void {
        if (! AutXmlFeature::isGloballyEnabled()) {
            return;
        }

        $cursor = OfficeDistributionCursor::query()->find($this->officeDistributionCursorId);
        if ($cursor === null || $cursor->channel !== CaptureChannel::NfeAutXmlDistDfe) {
            return;
        }

        if (! AutXmlFeature::isOfficeAllowed((int) $cursor->office_id)) {
            return;
        }

        if ($cursor->external_consumer_status === 'EXTERNAL_CONSUMER_CONFLICT') {
            Log::warning('sefaz.autxml.external_consumer_conflict', [
                'cursor_id' => $cursor->id,
                'office_id' => $cursor->office_id,
            ]);

            return;
        }

        $lockKey = 'sefaz:autxml:root:'.$cursor->office_id.':'.$cursor->interested_root_cnpj.':'.$cursor->environment;
        $lock = Cache::lock($lockKey, (int) config('sefaz.autxml.lock_ttl_seconds', 960));
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
            // Nunca usar ClientCredential — resolver já garante OfficeCredential.
            $identityCnpj = strtoupper($resolved['identity']->cnpj);
            $queryCnpj = strtoupper($cursor->query_cnpj);

            // Garante que o pedido DistDFe usa a mesma raiz do A1 do escritório (evita cStat 656).
            if (substr($identityCnpj, 0, 8) !== substr($queryCnpj, 0, 8)) {
                throw new AdnPermanentException(
                    'CNPJ-base do cursor NF-e autXML diverge da identidade do escritório.'
                );
            }

            $maxPages = (int) config('sefaz.autxml.max_pages_per_job', 20);
            $sleep = (float) config('sefaz.autxml.page_sleep_seconds', 2);
            $pages = 0;
            $totalDocs = 0;
            $totalQuarantine = 0;
            $cUf = '91'; // Ambiente Nacional para interessado autXML

            while ($pages < $maxPages) {
                $page = $client->distByNsu(
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

                if ($page->isAbuse() || $page->isEndOfQueue() || $page->isEmpty()) {
                    break;
                }

                if ($pages < $maxPages && $sleep > 0) {
                    usleep((int) ($sleep * 1_000_000));
                }
            }

            // O processador pode já ter gravado Idle/Blocked (ex.: cStat 137/656).
            // Sempre libera o lease deste owner e só agenda quiet se ainda estiver Running.
            $cursor->refresh();
            if ($cursor->status === SyncCursorStatus::Running) {
                $cursor->status = SyncCursorStatus::Idle;
                if ($cursor->next_sync_at === null || $cursor->next_sync_at->isPast()) {
                    $stillBehind = $cursor->max_nsu_seen !== null
                        && (int) $cursor->last_nsu < (int) $cursor->max_nsu_seen;
                    $cursor->next_sync_at = $stillBehind
                        ? now()->addSeconds(30)
                        : now()->addHours((float) config('sefaz.autxml.quiet_hours_after_empty', 1));
                }
            }
            if ($cursor->lock_owner === $owner || $cursor->status === SyncCursorStatus::Idle) {
                $cursor->locked_at = null;
                $cursor->lock_owner = null;
            }
            $cursor->save();

            if ($run) {
                $run->status = 'COMPLETED';
                $run->to_nsu = (int) $cursor->last_nsu;
                $run->pages_processed = $pages;
                $run->documents_persisted = $totalDocs;
                $run->documents_quarantined = $totalQuarantine;
                $run->finished_at = now();
                $run->save();
            }

            Log::info('sefaz.autxml.job.done', [
                'cursor_id' => $cursor->id,
                'office_id' => $cursor->office_id,
                'pages' => $pages,
                'documents' => $totalDocs,
                'quarantined' => $totalQuarantine,
                'last_nsu' => $cursor->last_nsu,
            ]);
        } catch (DocumentDecodeException $e) {
            $cursor->refresh();
            $this->failCursor(
                $cursor,
                $run,
                $e->getMessage(),
                permanent: $cursor->status === SyncCursorStatus::Blocked,
            );
        } catch (AdnPermanentException $e) {
            $this->failCursor($cursor, $run, $e->getMessage(), permanent: true);
        } catch (AdnRetryableException $e) {
            $this->failCursor($cursor, $run, $e->getMessage(), permanent: false);
        } catch (Throwable $e) {
            // Mensagem fixa no banco; detalhe só em log (sem getMessage — risco SOAP/path/PFX).
            Log::error('sefaz.autxml.job.error', [
                'cursor_id' => $this->officeDistributionCursorId,
                'exception' => class_basename($e),
            ]);
            $this->failCursor($cursor, $run, 'Falha interna no job NF-e autXML.', permanent: false);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function failCursor(
        ?OfficeDistributionCursor $cursor,
        ?OfficeDistributionRun $run,
        string $message,
        bool $permanent,
    ): void {
        if ($cursor) {
            $cursor->refresh();
            $cursor->last_error = mb_substr($message, 0, 500);
            $cursor->status = $permanent ? SyncCursorStatus::Blocked : SyncCursorStatus::Error;
            $cursor->locked_at = null;
            $cursor->lock_owner = null;
            $cursor->next_sync_at = now()->addMinutes($permanent ? 60 : 15);
            $cursor->save();
        }

        if ($run) {
            $run->status = 'FAILED';
            $run->error_code = $permanent ? 'PERMANENT' : 'RETRYABLE';
            $run->error_message = mb_substr($message, 0, 500);
            $run->finished_at = now();
            $run->save();
        }
    }
}
