<?php

namespace App\Services\Operations\Inbox;

use App\Enums\CaptureChannel;
use App\Enums\SyncCursorStatus;
use App\Models\ChannelSyncCursor;
use App\Models\SyncCursor;
use App\Models\SyncRun;
use Illuminate\Support\Collection;

/**
 * Cursores ADN / multi-canal e falhas recentes de sincronização.
 */
final class CursorSyncItemsCollector
{
    public function __construct(
        private readonly InboxItemFactory $items,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collect(int $officeId, InboxCapabilities $capabilities): Collection
    {
        // collect() base: Eloquent\Collection::merge() tenta getKey() em arrays de item.
        return collect()
            ->merge($this->cursorItems($officeId, $capabilities))
            ->merge($this->channelCursorItems($officeId, $capabilities))
            ->merge($this->syncFailedItems($officeId, $capabilities))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function cursorItems(int $officeId, InboxCapabilities $capabilities): Collection
    {
        $cursors = SyncCursor::query()
            ->where('office_id', $officeId)
            ->whereIn('status', [SyncCursorStatus::Blocked, SyncCursorStatus::Error])
            ->with(['establishment.client'])
            ->orderBy('id')
            ->get();

        return collect($cursors->map(function (SyncCursor $cursor) use ($capabilities) {
            $establishment = $cursor->establishment;
            $client = $establishment?->client;
            if ($establishment === null || $client === null) {
                return null;
            }

            $type = $cursor->status === SyncCursorStatus::Blocked
                ? 'cursor_blocked'
                : 'cursor_error';

            $envLabel = is_string($cursor->environment) && $cursor->environment !== ''
                ? $cursor->environment
                : null;

            $body = $type === 'cursor_blocked'
                ? 'Cursor ADN bloqueado. Intervenção necessária antes de retomar a captura.'
                : 'Cursor ADN em erro. Verifique o histórico de sincronização.';

            if ($envLabel !== null) {
                $body .= ' Ambiente: '.$envLabel.'.';
            }

            $sanitizedError = $this->items->sanitizeText($cursor->last_error);
            if ($sanitizedError !== null && $sanitizedError !== '') {
                $body .= ' '.$sanitizedError;
            }

            $titleBase = $type === 'cursor_blocked'
                ? 'Cursor ADN bloqueado: '.$this->items->clientLabel($client)
                : 'Cursor ADN com erro: '.$this->items->clientLabel($client);

            return $this->items->item(
                type: $type,
                title: $envLabel !== null ? $titleBase.' ('.$envLabel.')' : $titleBase,
                body: $body,
                reasons: [$type],
                clientId: $client->id,
                establishmentId: $establishment->id,
                occurredAt: $cursor->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                role: $capabilities,
                establishment: $establishment,
                cursor: $cursor,
            );
        })->filter()->values()->all());
    }

    /**
     * Cursores multi-canal SEFAZ (NF-e DistDFe, CT-e, …) em channel_sync_cursors.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function channelCursorItems(int $officeId, InboxCapabilities $capabilities): Collection
    {
        $cursors = ChannelSyncCursor::query()
            ->where('office_id', $officeId)
            ->whereIn('status', [SyncCursorStatus::Blocked, SyncCursorStatus::Error])
            ->with(['establishment.client'])
            ->orderBy('id')
            ->get();

        return collect($cursors->map(function (ChannelSyncCursor $cursor) use ($capabilities) {
            $establishment = $cursor->establishment;
            $client = $establishment?->client;
            if ($establishment === null || $client === null) {
                return null;
            }

            $channel = $cursor->channel instanceof CaptureChannel
                ? $cursor->channel
                : CaptureChannel::tryFrom((string) $cursor->channel);
            $channelLabel = $channel?->label() ?? (string) ($cursor->channel?->value ?? $cursor->channel ?? 'SEFAZ');

            $type = $cursor->status === SyncCursorStatus::Blocked
                ? 'cursor_blocked'
                : 'cursor_error';

            $envLabel = is_string($cursor->environment) && $cursor->environment !== ''
                ? $cursor->environment
                : null;

            $body = $type === 'cursor_blocked'
                ? "Cursor {$channelLabel} bloqueado (cStat ".($cursor->last_cstat ?? '—').').'
                : "Cursor {$channelLabel} em erro.";

            if ($envLabel !== null) {
                $body .= ' Ambiente: '.$envLabel.'.';
            }

            $sanitizedError = $this->items->sanitizeText($cursor->last_error);
            if ($sanitizedError !== null && $sanitizedError !== '') {
                $body .= ' '.$sanitizedError;
            }

            $titleBase = $type === 'cursor_blocked'
                ? "Cursor {$channelLabel} bloqueado: ".$this->items->clientLabel($client)
                : "Cursor {$channelLabel} com erro: ".$this->items->clientLabel($client);

            // item() espera SyncCursor; passamos null e embutimos id no subject via reasons/title uniqueness
            $item = $this->items->item(
                type: $type,
                title: $envLabel !== null ? $titleBase.' ('.$envLabel.')' : $titleBase,
                body: $body,
                reasons: [$type, 'channel:'.($channel?->value ?? 'unknown'), 'chcur'.$cursor->id],
                clientId: $client->id,
                establishmentId: $establishment->id,
                occurredAt: $cursor->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                role: $capabilities,
                establishment: $establishment,
                cursor: null,
            );
            // id estável e distinto de cursores ADN
            $item['id'] = substr(hash('sha256', 'channel:'.$type.':'.$cursor->id), 0, 32);

            return $item;
        })->filter()->values()->all());
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function syncFailedItems(int $officeId, InboxCapabilities $capabilities): Collection
    {
        $since = now()->subDay();

        $failedRuns = SyncRun::query()
            ->where('office_id', $officeId)
            ->where('status', 'FAILED')
            ->where('created_at', '>=', $since)
            ->with(['cursor.establishment.client'])
            ->orderByDesc('id')
            ->get();

        $seenEstablishments = [];
        $items = collect();

        foreach ($failedRuns as $run) {
            $cursor = $run->cursor;
            $establishment = $cursor?->establishment;
            $client = $establishment?->client;
            if ($establishment === null || $client === null) {
                continue;
            }
            // Já coberto por item de cursor BLOCKED/ERROR no mesmo estabelecimento.
            if ($cursor !== null && in_array($cursor->status, [SyncCursorStatus::Blocked, SyncCursorStatus::Error], true)) {
                continue;
            }
            if (isset($seenEstablishments[$establishment->id])) {
                continue;
            }
            $seenEstablishments[$establishment->id] = true;

            $items->push($this->items->item(
                type: 'sync_failed_recent',
                title: 'Falha de sincronização: '.$this->items->clientLabel($client),
                body: $this->items->sanitizeText($run->error_message)
                    ?? 'Falha sanitizada na sincronização ADN nas últimas 24 horas.',
                reasons: ['sync_failed_recent'],
                clientId: $client->id,
                establishmentId: $establishment->id,
                occurredAt: $run->finished_at?->toIso8601String()
                    ?? $run->created_at?->toIso8601String()
                    ?? now()->toIso8601String(),
                role: $capabilities,
                establishment: $establishment,
                cursor: $cursor,
            ));
        }

        return $items->values();
    }
}
