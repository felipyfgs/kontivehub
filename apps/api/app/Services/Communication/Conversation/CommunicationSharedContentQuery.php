<?php

namespace App\Services\Communication\Conversation;

use App\Models\CommunicationAttachment;
use App\Models\CommunicationMessage;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/** Read-only, allowlisted projection used by the contact/conversation galleries. */
final class CommunicationSharedContentQuery
{
    /**
     * @param  list<int>  $conversationIds
     * @return array{data:Collection<int,array<string,mixed>>,meta:array<string,mixed>}
     */
    public function paginate(
        int $tenantId,
        array $conversationIds,
        string $category,
        int $limit,
        ?string $cursor,
        ?string $scopeKey = null,
    ): array {
        $limit = max(1, min(100, $limit));
        $conversationIds = array_values(array_unique(array_map('intval', $conversationIds)));
        sort($conversationIds, SORT_NUMERIC);
        $scope = hash('sha256', $scopeKey ?? implode(',', $conversationIds));
        $decoded = $cursor === null ? null : $this->decode($cursor, $category, $tenantId, $scope);
        $snapshot = $decoded['snapshot'] ?? (int) (CommunicationMessage::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('conversation_id', $conversationIds)
            ->visibleToWorkspace()
            ->max('id') ?? 0);

        if ($snapshot < 1 || $conversationIds === []) {
            return $this->result(collect(), null, $snapshot, 0, $limit);
        }

        $attachmentSnapshot = $decoded['attachment_snapshot'] ?? ($category === 'links'
            ? 0
            : (int) (CommunicationAttachment::query()
                ->withoutGlobalScope('tenant')
                ->join('communication_messages as snapshot_message', 'snapshot_message.id', '=', 'communication_attachments.message_id')
                ->where('communication_attachments.tenant_id', $tenantId)
                ->where('snapshot_message.tenant_id', $tenantId)
                ->whereIn('snapshot_message.conversation_id', $conversationIds)
                ->where('snapshot_message.id', '<=', $snapshot)
                ->whereNull('communication_attachments.purged_at')
                ->tap(fn (Builder $query): Builder => $this->visibleSharedMessages($query, 'snapshot_message'))
                ->max('communication_attachments.id') ?? 0));

        return $category === 'links'
            ? $this->paginateLinks($tenantId, $conversationIds, $limit, $snapshot, $scope, $decoded)
            : $this->paginateAttachments($tenantId, $conversationIds, $category, $limit, $snapshot, $attachmentSnapshot, $scope, $decoded);
    }

    /**
     * @param  list<int>  $conversationIds
     * @param  array<string,mixed>|null  $cursor
     * @return array{data:Collection<int,array<string,mixed>>,meta:array<string,mixed>}
     */
    private function paginateAttachments(
        int $tenantId,
        array $conversationIds,
        string $category,
        int $limit,
        int $snapshot,
        int $attachmentSnapshot,
        string $scope,
        ?array $cursor,
    ): array {
        if ($cursor !== null && ($cursor['type'] ?? null) !== 'attachment') {
            throw new InvalidArgumentException('Cursor de conteúdo compartilhado inválido.');
        }

        $query = CommunicationAttachment::query()
            ->withoutGlobalScope('tenant')
            ->join('communication_messages as shared_message', 'shared_message.id', '=', 'communication_attachments.message_id')
            ->where('communication_attachments.tenant_id', $tenantId)
            ->where('shared_message.tenant_id', $tenantId)
            ->whereIn('shared_message.conversation_id', $conversationIds)
            ->where('shared_message.id', '<=', $snapshot)
            ->where('communication_attachments.id', '<=', $attachmentSnapshot)
            ->whereNull('communication_attachments.purged_at')
            ->tap(fn (Builder $query): Builder => $this->visibleSharedMessages($query, 'shared_message'));

        $mediaSql = "(COALESCE(communication_attachments.mime_type, '') LIKE 'image/%' OR COALESCE(communication_attachments.mime_type, '') LIKE 'audio/%' OR COALESCE(communication_attachments.mime_type, '') LIKE 'video/%' OR COALESCE(shared_message.kind, '') = 'STICKER')";
        $query->whereRaw($category === 'media' ? $mediaSql : 'NOT '.$mediaSql);

        if ($cursor !== null) {
            $this->beforeAttachment($query, $cursor);
        }

        $rows = $query
            ->orderByDesc('shared_message.occurred_at')
            ->orderByDesc('shared_message.id')
            ->orderByDesc('communication_attachments.id')
            ->limit($limit + 1)
            ->get([
                'communication_attachments.*',
                'shared_message.id as shared_message_id',
                'shared_message.conversation_id as shared_conversation_id',
                'shared_message.occurred_at as shared_occurred_at',
            ]);
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $items = $page->map(fn (CommunicationAttachment $attachment): array => $this->attachmentItem($attachment, $category));
        $last = $page->last();
        $next = $hasMore && $last instanceof CommunicationAttachment
            ? $this->encode(
                $category,
                $tenantId,
                $scope,
                $snapshot,
                $attachmentSnapshot,
                'attachment',
                $this->timestamp($last->getAttribute('shared_occurred_at')),
                (int) $last->getAttribute('shared_message_id'),
                (int) $last->id,
            )
            : null;

        return $this->result($items, $next, $snapshot, $attachmentSnapshot, $limit);
    }

    /**
     * Links ficam cifrados no payload da mensagem e não podem ser filtrados no SQL.
     * A consulta percorre lotes limitados até preencher a página e devolve cursor
     * sobre a última mensagem efetivamente consumida.
     *
     * @param  list<int>  $conversationIds
     * @param  array<string,mixed>|null  $cursor
     * @return array{data:Collection<int,array<string,mixed>>,meta:array<string,mixed>}
     */
    private function paginateLinks(
        int $tenantId,
        array $conversationIds,
        int $limit,
        int $snapshot,
        string $scope,
        ?array $cursor,
    ): array {
        if ($cursor !== null && ($cursor['type'] ?? null) !== 'link') {
            throw new InvalidArgumentException('Cursor de conteúdo compartilhado inválido.');
        }

        $items = collect();
        $after = $cursor;
        $lastScanned = null;
        $messagesById = [];
        $reachedEnd = false;
        $scanned = 0;
        $batchSize = min(200, max(50, $limit * 2));

        while ($items->count() <= $limit && $scanned < 1000) {
            $query = $this->messages($tenantId, $conversationIds, $snapshot);
            if ($after !== null) {
                $this->beforeMessage($query, (string) $after['occurred_at'], (int) $after['message_id']);
            }
            $messages = $query
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit($batchSize)
                ->get();
            if ($messages->isEmpty()) {
                $reachedEnd = true;
                break;
            }

            foreach ($messages as $message) {
                $scanned++;
                $lastScanned = $message;
                $messagesById[(int) $message->id] = $message;
                $item = $this->linkItem($message);
                if ($item !== null) {
                    $items->push($item);
                    if ($items->count() > $limit) {
                        break 2;
                    }
                }
            }

            if ($messages->count() < $batchSize) {
                $reachedEnd = true;
                break;
            }
            if (! $lastScanned instanceof CommunicationMessage || $lastScanned->occurred_at === null) {
                throw new LogicException('Mensagem compartilhada sem ordenação temporal.');
            }
            $after = [
                'occurred_at' => $this->timestamp($lastScanned->occurred_at),
                'message_id' => (int) $lastScanned->id,
            ];
        }

        $hasExtraItem = $items->count() > $limit;
        $page = $items->take($limit)->values();
        $cursorMessage = $reachedEnd ? null : $lastScanned;
        if ($hasExtraItem) {
            $lastItem = $page->last();
            $lastItemId = is_array($lastItem) ? (int) $lastItem['message_id'] : 0;
            $cursorMessage = $messagesById[$lastItemId] ?? null;
            if (! $cursorMessage instanceof CommunicationMessage) {
                throw new LogicException('Cursor de links perdeu a mensagem carregada.');
            }
        }
        $next = $cursorMessage instanceof CommunicationMessage
            ? $this->encode(
                'links',
                $tenantId,
                $scope,
                $snapshot,
                0,
                'link',
                $this->timestamp($cursorMessage->occurred_at),
                (int) $cursorMessage->id,
                0,
            )
            : null;

        return $this->result($page, $next, $snapshot, 0, $limit);
    }

    /** @param list<int> $conversationIds @return Builder<CommunicationMessage> */
    private function messages(int $tenantId, array $conversationIds, int $snapshot): Builder
    {
        return CommunicationMessage::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('conversation_id', $conversationIds)
            ->visibleToWorkspace()
            ->where('id', '<=', $snapshot)
            ->whereNotNull('occurred_at')
            ->whereNull('purged_at')
            ->whereNull('revoked_at')
            ->whereRaw("(NOT COALESCE(jsonb_exists(metadata, 'view_once'), false) OR metadata->'view_once' = 'false'::jsonb)");
    }

    /** @param Builder<CommunicationAttachment> $query @param array<string,mixed> $cursor */
    private function beforeAttachment(Builder $query, array $cursor): void
    {
        $query->where(function (Builder $builder) use ($cursor): void {
            $builder->where('shared_message.occurred_at', '<', $cursor['occurred_at'])
                ->orWhere(function (Builder $sameTime) use ($cursor): void {
                    $sameTime->where('shared_message.occurred_at', $cursor['occurred_at'])
                        ->where(function (Builder $tuple) use ($cursor): void {
                            $tuple->where('shared_message.id', '<', $cursor['message_id'])
                                ->orWhere(function (Builder $sameMessage) use ($cursor): void {
                                    $sameMessage->where('shared_message.id', $cursor['message_id'])
                                        ->where('communication_attachments.id', '<', $cursor['item_id']);
                                });
                        });
                });
        });
    }

    /** @param Builder<CommunicationMessage> $query */
    private function beforeMessage(Builder $query, string $occurredAt, int $messageId): void
    {
        $query->where(function (Builder $builder) use ($occurredAt, $messageId): void {
            $builder->where('occurred_at', '<', $occurredAt)
                ->orWhere(function (Builder $sameTime) use ($occurredAt, $messageId): void {
                    $sameTime->where('occurred_at', $occurredAt)->where('id', '<', $messageId);
                });
        });
    }

    /** @return array<string,mixed> */
    private function attachmentItem(CommunicationAttachment $attachment, string $category): array
    {
        $messageId = (int) $attachment->getAttribute('shared_message_id');
        $mimeType = (string) $attachment->mime_type;

        return [
            'id' => 'attachment-'.$attachment->id,
            'type' => 'attachment',
            'category' => $category,
            'attachment_id' => (int) $attachment->id,
            'message_id' => $messageId,
            'conversation_id' => (int) $attachment->getAttribute('shared_conversation_id'),
            'mime_type' => $mimeType,
            'filename' => $attachment->original_name_encrypted ?: 'anexo-'.$attachment->id,
            'size_bytes' => (int) $attachment->size_bytes,
            'occurred_at' => $this->timestamp($attachment->getAttribute('shared_occurred_at')),
            'download_url' => '/api/v1/communication/attachments/'.$attachment->id.'/download',
            'preview_url' => (str_starts_with($mimeType, 'image/')
                || str_starts_with($mimeType, 'audio/')
                || str_starts_with($mimeType, 'video/'))
                ? '/api/v1/communication/attachments/'.$attachment->id.'/preview'
                : null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function linkItem(CommunicationMessage $message): ?array
    {
        $url = data_get($message->content_encrypted, 'link_preview.url');
        if (! is_string($url)
            || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }

        return [
            'id' => 'link-'.$message->id,
            'type' => 'link',
            'category' => 'links',
            'message_id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'url' => $url,
            'title' => data_get($message->content_encrypted, 'link_preview.title'),
            'description' => data_get($message->content_encrypted, 'link_preview.description'),
            'occurred_at' => $this->timestamp($message->occurred_at),
        ];
    }

    private function visibleSharedMessages(Builder $query, string $alias): Builder
    {
        return CommunicationMessage::constrainWorkspaceVisibility($query, $alias)
            ->whereNotNull($alias.'.occurred_at')
            ->whereNull($alias.'.purged_at')
            ->whereNull($alias.'.revoked_at')
            ->whereRaw("(NOT COALESCE(jsonb_exists({$alias}.metadata, 'view_once'), false) OR {$alias}.metadata->'view_once' = 'false'::jsonb)");
    }

    private function encode(
        string $category,
        int $tenantId,
        string $scope,
        int $snapshot,
        int $attachmentSnapshot,
        string $type,
        string $occurredAt,
        int $messageId,
        int $itemId,
    ): string {
        $payload = rtrim(strtr(base64_encode(json_encode([
            'v' => 4,
            'category' => $category,
            'tenant_id' => $tenantId,
            'scope' => $scope,
            'snapshot' => $snapshot,
            'attachment_snapshot' => $attachmentSnapshot,
            'type' => $type,
            'occurred_at' => $occurredAt,
            'message_id' => $messageId,
            'item_id' => $itemId,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $payload, $this->cursorSigningKey());

        return $payload.'.'.$signature;
    }

    /** @return array{snapshot:int,attachment_snapshot:int,type:string,occurred_at:string,message_id:int,item_id:int} */
    private function decode(string $cursor, string $category, int $tenantId, string $scope): array
    {
        [$payload, $signature] = array_pad(explode('.', $cursor, 2), 2, null);
        if (! is_string($payload)
            || ! is_string($signature)
            || ! preg_match('/^[a-f0-9]{64}$/', $signature)
            || ! hash_equals(hash_hmac('sha256', $payload, $this->cursorSigningKey()), $signature)) {
            throw new InvalidArgumentException('Cursor de conteúdo compartilhado inválido.');
        }
        $raw = base64_decode(strtr($payload.str_repeat('=', (4 - strlen($payload) % 4) % 4), '-_', '+/'), true);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data)
            || ($data['v'] ?? null) !== 4
            || ($data['category'] ?? null) !== $category
            || ($data['tenant_id'] ?? null) !== $tenantId
            || ($data['scope'] ?? null) !== $scope
            || ! in_array(($data['type'] ?? null), ['attachment', 'link'], true)
            || ! is_int($data['snapshot'] ?? null)
            || ($data['snapshot'] ?? 0) < 1
            || ! is_int($data['attachment_snapshot'] ?? null)
            || ($data['attachment_snapshot'] ?? -1) < 0
            || ! is_string($data['occurred_at'] ?? null)
            || ! $this->validTimestamp($data['occurred_at'] ?? '')
            || ! is_int($data['message_id'] ?? null)
            || ($data['message_id'] ?? 0) < 1
            || ! is_int($data['item_id'] ?? null)
            || ($data['item_id'] ?? -1) < 0
            || (($data['type'] ?? null) === 'attachment'
                && (($data['item_id'] ?? 0) < 1
                    || ($data['attachment_snapshot'] ?? 0) < ($data['item_id'] ?? 0)))
            || (($data['type'] ?? null) === 'link'
                && (($data['item_id'] ?? -1) !== 0
                    || ($data['attachment_snapshot'] ?? -1) !== 0))) {
            throw new InvalidArgumentException('Cursor de conteúdo compartilhado inválido.');
        }

        return [
            'snapshot' => $data['snapshot'],
            'attachment_snapshot' => $data['attachment_snapshot'],
            'type' => $data['type'],
            'occurred_at' => $data['occurred_at'],
            'message_id' => $data['message_id'],
            'item_id' => $data['item_id'],
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $items
     * @return array{data:Collection<int,array<string,mixed>>,meta:array<string,mixed>}
     */
    private function result(
        Collection $items,
        ?string $nextCursor,
        int $snapshot,
        int $attachmentSnapshot,
        int $limit,
    ): array {
        return [
            'data' => $items,
            'meta' => [
                'next_cursor' => $nextCursor,
                'snapshot_through_message_id' => $snapshot > 0 ? $snapshot : null,
                'snapshot_through_attachment_id' => $attachmentSnapshot > 0 ? $attachmentSnapshot : null,
                'limit' => $limit,
            ],
        ];
    }

    private function timestamp(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->format('Y-m-d\TH:i:s.uP');
        }
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Timestamp de conteúdo compartilhado inválido.');
        }

        try {
            return CarbonImmutable::parse($value)->format('Y-m-d\TH:i:s.uP');
        } catch (Throwable) {
            throw new InvalidArgumentException('Timestamp de conteúdo compartilhado inválido.');
        }
    }

    private function validTimestamp(string $value): bool
    {
        if ($value === '' || strlen($value) > 64) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $value);

        return $parsed !== false && $parsed->format('Y-m-d\TH:i:s.uP') === $value;
    }

    private function cursorSigningKey(): string
    {
        $key = (string) config('app.key', '');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = is_string($decoded) ? $decoded : '';
        }
        if ($key === '') {
            throw new RuntimeException('APP_KEY é obrigatória para assinar cursores.');
        }

        return hash_hmac('sha256', 'communication-shared-content-cursor-v4', $key, true);
    }
}
