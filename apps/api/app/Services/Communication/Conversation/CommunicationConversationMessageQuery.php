<?php

namespace App\Services\Communication\Conversation;

use App\Models\CommunicationConversation;
use App\Models\CommunicationConversationReadState;
use App\Models\CommunicationConversationUnreadMessage;
use App\Models\CommunicationMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class CommunicationConversationMessageQuery
{
    /**
     * @return array{data: Collection<int, CommunicationMessage>, meta: array<string, mixed>}
     */
    public function paginate(
        CommunicationConversation $conversation,
        int $limit = 50,
        ?string $cursor = null,
        string $anchor = 'latest',
        ?int $messageId = null,
    ): array {
        $limit = max(1, min(100, $limit));
        $snapshotThrough = (int) (CommunicationMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->visibleToWorkspace()
            ->max('id') ?? 0);
        $direction = null;
        $cursorTuple = null;
        if ($cursor !== null) {
            $decoded = $this->decodeCursor($cursor, (int) $conversation->id);
            $direction = $decoded['direction'];
            $cursorTuple = [$decoded['occurred_at'], $decoded['id']];
            $snapshotThrough = $decoded['snapshot_through_message_id'];
        }

        $query = $this->baseQuery($conversation, $snapshotThrough);
        if ($direction === 'older' && $cursorTuple !== null) {
            $this->before($query, $cursorTuple[0], $cursorTuple[1]);
            $messages = $query
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values();
        } elseif ($direction === 'newer' && $cursorTuple !== null) {
            $this->after($query, $cursorTuple[0], $cursorTuple[1]);
            $messages = $query
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->values();
        } elseif ($anchor === 'message') {
            $message = $messageId === null ? null : $this->baseQuery($conversation, $snapshotThrough)
                ->whereNull('purged_at')
                ->whereNull('revoked_at')
                ->whereKey($messageId)
                ->first();
            if ($message === null) {
                throw new InvalidArgumentException('Mensagem âncora indisponível.');
            }
            $this->atOrAfter($query, $message);
            $messages = $query->orderBy('occurred_at')->orderBy('id')->limit($limit)->get()->values();
        } elseif ($anchor === 'first_unread') {
            $firstUnread = $this->firstUnreadMessage($conversation, $snapshotThrough);
            if ($firstUnread === null) {
                $messages = $this->latest($query, $limit);
            } else {
                $this->atOrAfter($query, $firstUnread);
                $messages = $query
                    ->orderBy('occurred_at')
                    ->orderBy('id')
                    ->limit($limit)
                    ->get()
                    ->values();
            }
        } else {
            $messages = $this->latest($query, $limit);
        }

        return $this->result($conversation, $messages, $limit, $snapshotThrough);
    }

    /** @return Builder<CommunicationMessage> */
    private function baseQuery(
        CommunicationConversation $conversation,
        int $snapshotThrough,
    ): Builder {
        return CommunicationMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->visibleToWorkspace()
            ->when(
                $snapshotThrough > 0,
                fn (Builder $query) => $query->where('id', '<=', $snapshotThrough),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            )
            ->with('attachments');
    }

    /** @param Builder<CommunicationMessage> $query */
    private function before(Builder $query, string $occurredAt, int $id): void
    {
        $query->where(function (Builder $builder) use ($occurredAt, $id): void {
            $builder->where('occurred_at', '<', $occurredAt)
                ->orWhere(function (Builder $inner) use ($occurredAt, $id): void {
                    $inner->where('occurred_at', $occurredAt)->where('id', '<', $id);
                });
        });
    }

    /** @param Builder<CommunicationMessage> $query */
    private function after(Builder $query, string $occurredAt, int $id): void
    {
        $query->where(function (Builder $builder) use ($occurredAt, $id): void {
            $builder->where('occurred_at', '>', $occurredAt)
                ->orWhere(function (Builder $inner) use ($occurredAt, $id): void {
                    $inner->where('occurred_at', $occurredAt)->where('id', '>', $id);
                });
        });
    }

    /** @param Builder<CommunicationMessage> $query */
    private function atOrAfter(Builder $query, CommunicationMessage $anchor): void
    {
        $query->where(function (Builder $builder) use ($anchor): void {
            $builder->where('occurred_at', '>', $anchor->occurred_at)
                ->orWhere(function (Builder $inner) use ($anchor): void {
                    $inner->where('occurred_at', $anchor->occurred_at)
                        ->where('id', '>=', $anchor->id);
                });
        });
    }

    /**
     * @param  Builder<CommunicationMessage>  $query
     * @return Collection<int, CommunicationMessage>
     */
    private function latest(Builder $query, int $limit): Collection
    {
        return $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    private function firstUnreadMessage(
        CommunicationConversation $conversation,
        int $snapshotThrough,
    ): ?CommunicationMessage {
        if ($snapshotThrough < 1) {
            return null;
        }

        return CommunicationMessage::query()
            ->withoutGlobalScopes()
            ->join(
                'communication_conversation_unread_messages',
                'communication_conversation_unread_messages.message_id',
                '=',
                'communication_messages.id',
            )
            ->where('communication_conversation_unread_messages.tenant_id', $conversation->tenant_id)
            ->where('communication_conversation_unread_messages.conversation_id', $conversation->id)
            ->where('communication_messages.id', '<=', $snapshotThrough)
            ->whereNull('communication_messages.quarantined_at')
            ->select('communication_messages.*')
            ->orderBy('communication_messages.occurred_at')
            ->orderBy('communication_messages.id')
            ->first();
    }

    /**
     * @param  Collection<int, CommunicationMessage>  $messages
     * @return array{data: Collection<int, CommunicationMessage>, meta: array<string, mixed>}
     */
    private function result(
        CommunicationConversation $conversation,
        Collection $messages,
        int $limit,
        int $snapshotThrough,
    ): array {
        $first = $messages->first();
        $last = $messages->last();
        $firstUnread = $this->firstUnreadMessage($conversation, $snapshotThrough);
        $unreadCount = CommunicationConversationUnreadMessage::query()
            ->withoutGlobalScopes()
            ->whereHas('message')
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->when(
                $snapshotThrough > 0,
                fn ($query) => $query->where('message_id', '<=', $snapshotThrough),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->count();
        $readState = CommunicationConversationReadState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->first();

        $hasOlder = $first !== null && $this->hasBefore($conversation, $first, $snapshotThrough);
        $hasNewer = $last !== null && $this->hasAfter($conversation, $last, $snapshotThrough);

        return [
            'data' => $messages,
            'meta' => [
                'older_cursor' => $hasOlder
                    ? $this->encodeCursor('older', $first, $snapshotThrough)
                    : null,
                'newer_cursor' => $hasNewer
                    ? $this->encodeCursor('newer', $last, $snapshotThrough)
                    : null,
                'first_unread_message_id' => $firstUnread?->id,
                'snapshot_through_message_id' => $snapshotThrough > 0 ? $snapshotThrough : null,
                'read_state_version' => (int) ($readState?->version ?? 0),
                'unread_count' => $unreadCount,
                'limit' => $limit,
            ],
        ];
    }

    private function hasBefore(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
        int $snapshotThrough,
    ): bool {
        $query = $this->baseQuery($conversation, $snapshotThrough);
        $this->before($query, $this->cursorTimestamp($message), (int) $message->id);

        return $query->exists();
    }

    private function hasAfter(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
        int $snapshotThrough,
    ): bool {
        $query = $this->baseQuery($conversation, $snapshotThrough);
        $this->after($query, $this->cursorTimestamp($message), (int) $message->id);

        return $query->exists();
    }

    private function encodeCursor(
        string $direction,
        CommunicationMessage $message,
        int $snapshotThrough,
    ): string {
        $json = json_encode([
            'v' => 1,
            'conversation_id' => (int) $message->conversation_id,
            'direction' => $direction,
            'occurred_at' => $this->cursorTimestamp($message),
            'id' => (int) $message->id,
            'snapshot_through_message_id' => $snapshotThrough,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{direction:string, occurred_at:string, id:int, snapshot_through_message_id:int}
     */
    private function decodeCursor(string $cursor, int $conversationId): array
    {
        $padding = strlen($cursor) % 4;
        if ($padding > 0) {
            $cursor .= str_repeat('=', 4 - $padding);
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($decoded)
            || ($decoded['v'] ?? null) !== 1
            || ! is_int($decoded['conversation_id'] ?? null)
            || $decoded['conversation_id'] !== $conversationId
            || ! in_array($decoded['direction'] ?? null, ['older', 'newer'], true)
            || ! is_string($decoded['occurred_at'] ?? null)
            || strtotime($decoded['occurred_at']) === false
            || ! is_int($decoded['id'] ?? null)
            || (int) $decoded['id'] < 1
            || ! is_int($decoded['snapshot_through_message_id'] ?? null)
            || (int) $decoded['snapshot_through_message_id'] < 1) {
            throw new InvalidArgumentException('Cursor de timeline inválido.');
        }

        return [
            'direction' => $decoded['direction'],
            'occurred_at' => $decoded['occurred_at'],
            'id' => $decoded['id'],
            'snapshot_through_message_id' => $decoded['snapshot_through_message_id'],
        ];
    }

    private function cursorTimestamp(CommunicationMessage $message): string
    {
        return $message->occurred_at->format('Y-m-d\\TH:i:s.uP');
    }
}
