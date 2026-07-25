<?php

namespace App\Services\Sefaz;

use App\Enums\CaptureChannel;
use App\Enums\SyncCursorStatus;
use App\Jobs\SyncSefazCteDistDfeJob;
use App\Jobs\SyncSefazDistDfeJob;
use App\Models\ChannelSyncCursor;
use Illuminate\Support\Facades\Log;

/**
 * Enfileira cursores DistDFe vencidos (NF-e e CT-e; NSU independente por canal).
 * MDF-e deliberadamente fora do dispatch operacional.
 */
final class SefazSyncDispatchService
{
    public function __construct(
        private readonly ChannelSyncCursorService $channelCursors,
    ) {}

    public function dispatchDue(int $limit = 50): int
    {
        // Clientes elegíveis sem cursor NFE/CTE nunca entrariam no loop só com SELECT de cursores.
        $provisioned = $this->channelCursors->ensureMissingForEligible($limit);
        if ($provisioned > 0) {
            Log::info('sefaz.channel_cursor.bootstrap', ['created' => $provisioned]);
        }

        $n = 0;
        $n += $this->dispatchChannel(
            CaptureChannel::NfeDistDfe,
            (bool) config('sefaz.distdfe_enabled'),
            fn (int $id) => SyncSefazDistDfeJob::dispatch($id, 'SCHEDULED'),
            $limit,
            'sefaz.distdfe.dispatch',
        );
        $n += $this->dispatchChannel(
            CaptureChannel::CteDistDfe,
            (bool) config('sefaz.cte_enabled'),
            fn (int $id) => SyncSefazCteDistDfeJob::dispatch($id, 'SCHEDULED'),
            $limit,
            'sefaz.cte.dispatch',
        );

        return $n;
    }

    /**
     * @param  callable(int): void  $dispatch
     */
    private function dispatchChannel(
        CaptureChannel $channel,
        bool $enabled,
        callable $dispatch,
        int $limit,
        string $logKey,
    ): int {
        if (! $enabled) {
            return 0;
        }

        $now = now();
        $cursors = ChannelSyncCursor::query()
            ->where('channel', $channel)
            ->whereIn('status', [
                SyncCursorStatus::Idle->value,
                SyncCursorStatus::Error->value,
                SyncCursorStatus::Waiting->value,
            ])
            ->where(function ($q) use ($now): void {
                $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', $now);
            })
            ->orderBy('next_sync_at')
            ->limit($limit)
            ->get();

        $n = 0;
        foreach ($cursors as $cursor) {
            $spreadSeconds = ($cursor->id % 60) * 10;
            if ($cursor->next_sync_at === null) {
                $cursor->next_sync_at = $now->copy()->addSeconds($spreadSeconds);
                $cursor->save();
            }

            $dispatch($cursor->id);
            $n++;
        }

        if ($n > 0) {
            Log::info($logKey, ['count' => $n, 'channel' => $channel->value]);
        }

        return $n;
    }
}
