<?php

namespace App\Jobs;

use App\Contracts\SefazDistDfeClient;
use App\Enums\CaptureChannel;
use App\Enums\CredentialStatus;
use App\Enums\SyncCursorStatus;
use App\Exceptions\Adn\AdnPermanentException;
use App\Exceptions\Adn\AdnRetryableException;
use App\Exceptions\Adn\DocumentDecodeException;
use App\Models\ChannelSyncCursor;
use App\Models\ClientCredential;
use App\Services\Certificates\CredentialService;
use App\Services\Sefaz\DistDfePageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sincroniza DistDFe NF-e para um channel_sync_cursor (flag sefaz.distdfe_enabled).
 */
class SyncSefazDistDfeJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries = 1;

    public function __construct(
        public int $channelSyncCursorId,
        public string $trigger = 'SCHEDULED',
    ) {
        $this->timeout = max(60, (int) config('sefaz.job_timeout_seconds', 900));
        $this->onQueue((string) config('sefaz.queues.nfe', 'sync-sefaz-nfe'));
    }

    public function handle(
        SefazDistDfeClient $client,
        DistDfePageProcessor $processor,
        CredentialService $credentials,
    ): void {
        if (! config('sefaz.distdfe_enabled')) {
            Log::notice('sefaz.distdfe.job.skipped', [
                'cursor_id' => $this->channelSyncCursorId,
                'reason' => 'feature_disabled',
            ]);

            return;
        }

        $cursor = ChannelSyncCursor::query()
            ->with(['establishment.client'])
            ->find($this->channelSyncCursorId);

        if ($cursor === null || $cursor->channel !== CaptureChannel::NfeDistDfe) {
            Log::warning('sefaz.distdfe.job.skipped', [
                'cursor_id' => $this->channelSyncCursorId,
                'reason' => $cursor === null ? 'cursor_not_found' : 'channel_mismatch',
            ]);

            return;
        }

        $lock = Cache::lock(
            'sefaz:distdfe:est:'.$cursor->establishment_id,
            (int) config('sefaz.lock_ttl_seconds', 960)
        );
        if (! $lock->get()) {
            Log::info('sefaz.distdfe.job.skipped', [
                'cursor_id' => $cursor->id,
                'reason' => 'establishment_lock_busy',
            ]);

            return;
        }

        $owner = (string) Str::uuid();

        try {
            $cursor->status = SyncCursorStatus::Running;
            $cursor->locked_at = now();
            $cursor->lock_owner = $owner;
            $cursor->attempts = (int) $cursor->attempts + 1;
            $cursor->save();

            $establishment = $cursor->establishment;
            $clientModel = $establishment?->client;
            if (! $establishment || ! $clientModel) {
                throw new AdnPermanentException('Estabelecimento/cliente ausente para DistDFe.');
            }

            $credential = ClientCredential::query()
                ->where('client_id', $clientModel->id)
                ->where('status', CredentialStatus::Active)
                ->first();
            if (! $credential) {
                throw new AdnPermanentException('Credencial A1 ativa ausente para DistDFe.');
            }

            $material = $credentials->loadPfxMaterial($credential);
            if ($material === null) {
                throw new AdnPermanentException('Não foi possível materializar A1 para DistDFe.');
            }
            $cUf = $this->resolveUfAutor($establishment);
            $maxPages = (int) config('sefaz.max_pages_per_job', 12);
            $sleep = (float) config('sefaz.page_sleep_seconds', 2);
            $pages = 0;
            $totalDocs = 0;

            while ($pages < $maxPages) {
                $page = $client->distByNsu(
                    $material,
                    $establishment->cnpj,
                    (int) $cursor->last_nsu,
                    $cUf,
                );

                $result = $processor->process($cursor, $establishment, $page);
                $totalDocs += $result['documents'];
                $cursor->refresh();
                $pages++;

                if ($page->isAbuse() || $page->isEndOfQueue() || $page->isEmpty()) {
                    break;
                }

                if ($pages < $maxPages && $sleep > 0) {
                    usleep((int) ($sleep * 1_000_000));
                }
            }

            if ($cursor->status === SyncCursorStatus::Running) {
                $cursor->status = SyncCursorStatus::Idle;
                if ($cursor->next_sync_at === null || $cursor->next_sync_at->isPast()) {
                    $stillBehind = $cursor->max_nsu_seen !== null
                        && (int) $cursor->last_nsu < (int) $cursor->max_nsu_seen;
                    $cursor->next_sync_at = $stillBehind
                        ? now()->addSeconds(30)
                        : now()->addHours((float) config('sefaz.quiet_hours_after_empty', 1));
                }
            }

            // O processor pode trocar RUNNING por IDLE/BLOCKED ao fechar a página.
            // O lease do banco deve ser liberado independentemente desse estado final.
            $cursor->locked_at = null;
            $cursor->lock_owner = null;
            $cursor->save();

            Log::info('sefaz.distdfe.job.done', [
                'cursor_id' => $cursor->id,
                'pages' => $pages,
                'documents' => $totalDocs,
                'last_nsu' => $cursor->last_nsu,
            ]);
        } catch (DocumentDecodeException $e) {
            $cursor->refresh();
            $this->failCursor(
                $cursor,
                $e->getMessage(),
                permanent: $cursor->status === SyncCursorStatus::Blocked,
            );
        } catch (AdnPermanentException $e) {
            $this->failCursor($cursor, $e->getMessage(), permanent: true);
        } catch (AdnRetryableException $e) {
            $this->failCursor($cursor, $e->getMessage(), permanent: false);
        } catch (Throwable $e) {
            $this->failCursor($cursor, 'Falha DistDFe: '.mb_substr($e->getMessage(), 0, 200), permanent: false);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function failCursor(?ChannelSyncCursor $cursor, string $message, bool $permanent): void
    {
        if (! $cursor) {
            return;
        }
        $cursor->refresh();
        $cursor->last_error = $message;
        $cursor->status = $permanent ? SyncCursorStatus::Blocked : SyncCursorStatus::Error;
        $cursor->locked_at = null;
        $cursor->lock_owner = null;
        $cursor->next_sync_at = now()->addMinutes($permanent ? 60 : 15);
        $cursor->save();
    }

    private function resolveUfAutor($establishment): string
    {
        $state = $establishment->address_state
            ?? (is_array($establishment->address ?? null) ? ($establishment->address['state'] ?? $establishment->address['uf'] ?? null) : null)
            ?? null;
        // cUFAutor: se desconhecido, 91 (AN) é aceito em vários cenários; preferir IBGE UF do cadastro
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
