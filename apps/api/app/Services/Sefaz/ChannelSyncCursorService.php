<?php

namespace App\Services\Sefaz;

use App\Enums\CaptureChannel;
use App\Enums\SyncCursorStatus;
use App\Jobs\SyncSefazCteDistDfeJob;
use App\Jobs\SyncSefazDistDfeJob;
use App\Models\ChannelSyncCursor;
use App\Models\Establishment;
use App\Services\Clients\CaptureEligibilityService;
use Illuminate\Support\Facades\Log;

/**
 * Garante cursores DistDFe (NF-e / CT-e) por estabelecimento e dispara captura.
 *
 * Sem este bootstrap, o scheduler só reprocessa cursores já existentes —
 * clientes novos ficam só no ADN (NFS-e) e nunca capturam NF-e de entrada.
 */
final class ChannelSyncCursorService
{
    public function __construct(
        private readonly CaptureEligibilityService $eligibility,
    ) {}

    /**
     * Canais SEFAZ operacionais com feature flag ligada (exclui ADN e MDF-e).
     *
     * @return list<CaptureChannel>
     */
    public function enabledSefazChannels(): array
    {
        $out = [];
        foreach ([CaptureChannel::NfeDistDfe, CaptureChannel::CteDistDfe] as $channel) {
            if ($channel->isEnabled()) {
                $out[] = $channel;
            }
        }

        return $out;
    }

    public function environment(): string
    {
        return (string) config('sefaz.environment', 'production');
    }

    public function ensure(
        Establishment $establishment,
        CaptureChannel $channel,
        ?string $environment = null,
    ): ChannelSyncCursor {
        $env = $environment ?? $this->environment();

        return ChannelSyncCursor::query()->firstOrCreate(
            [
                'establishment_id' => $establishment->id,
                'environment' => $env,
                'source' => $channel->source(),
                'channel' => $channel->value,
            ],
            [
                'tenant_id' => $establishment->tenant_id,
                'last_nsu' => 0,
                'status' => SyncCursorStatus::Idle,
                'next_sync_at' => now(),
            ]
        );
    }

    /**
     * Garante cursores SEFAZ habilitados para o estabelecimento (sem enfileirar).
     *
     * @return list<ChannelSyncCursor>
     */
    public function ensureEnabledForEstablishment(Establishment $establishment): array
    {
        $cursors = [];
        foreach ($this->enabledSefazChannels() as $channel) {
            $cursors[] = $this->ensure($establishment, $channel);
        }

        return $cursors;
    }

    /**
     * Garante cursores ausentes para estabelecimentos elegíveis (scheduler).
     * Limitado por tick para não travar o minuto.
     *
     * @return int quantidade de cursores criados
     */
    public function ensureMissingForEligible(int $limit = 50): int
    {
        $channels = $this->enabledSefazChannels();
        if ($channels === []) {
            return 0;
        }

        $env = $this->environment();
        $created = 0;

        $establishments = Establishment::query()
            ->with('client')
            ->where('capture_enabled', true)
            ->where('is_active', true)
            ->orderBy('id')
            ->limit(max(1, $limit * 4))
            ->get();

        foreach ($establishments as $establishment) {
            if ($created >= $limit) {
                break;
            }
            if (! $this->eligibility->isEligible($establishment)) {
                continue;
            }

            foreach ($channels as $channel) {
                if ($created >= $limit) {
                    break;
                }
                $exists = ChannelSyncCursor::query()
                    ->where('establishment_id', $establishment->id)
                    ->where('environment', $env)
                    ->where('source', $channel->source())
                    ->where('channel', $channel->value)
                    ->exists();
                if ($exists) {
                    continue;
                }
                $this->ensure($establishment, $channel, $env);
                $created++;
                Log::info('sefaz.channel_cursor.provisioned', [
                    'establishment_id' => $establishment->id,
                    'channel' => $channel->value,
                    'environment' => $env,
                ]);
            }
        }

        return $created;
    }

    /**
     * Enfileira captura DistDFe para cursores do estabelecimento (trigger manual).
     * Cria cursores se ainda não existirem.
     *
     * @return list<array{channel: string, channel_sync_cursor_id: int, dispatched: bool}>
     */
    public function dispatchManualForEstablishment(Establishment $establishment): array
    {
        $results = [];
        foreach ($this->ensureEnabledForEstablishment($establishment) as $cursor) {
            $channel = $cursor->channel instanceof CaptureChannel
                ? $cursor->channel
                : CaptureChannel::from((string) $cursor->channel);

            $dispatched = false;
            if (! in_array($cursor->status, [SyncCursorStatus::Blocked, SyncCursorStatus::Running], true)) {
                // Libera quiet period no disparo manual — operador pediu agora.
                if ($cursor->next_sync_at !== null && $cursor->next_sync_at->isFuture()) {
                    $cursor->next_sync_at = now();
                    $cursor->save();
                }

                if ($channel === CaptureChannel::NfeDistDfe) {
                    SyncSefazDistDfeJob::dispatch($cursor->id, 'MANUAL');
                    $dispatched = true;
                } elseif ($channel === CaptureChannel::CteDistDfe) {
                    SyncSefazCteDistDfeJob::dispatch($cursor->id, 'MANUAL');
                    $dispatched = true;
                }
            }

            $results[] = [
                'channel' => $channel->value,
                'channel_sync_cursor_id' => $cursor->id,
                'dispatched' => $dispatched,
            ];
        }

        return $results;
    }
}
