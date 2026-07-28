<?php

namespace App\Services\Communication\Conversation;

use App\Enums\Communication\MessageDirection;
use App\Exceptions\CommunicationConversationApiException;
use App\Models\CommunicationConversation;
use App\Models\CommunicationConversationReadState;
use App\Models\CommunicationConversationUnreadMessage;
use App\Models\CommunicationMessage;
use App\Services\Communication\Events\CommunicationEventRecorder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

final readonly class CommunicationConversationReadStateService
{
    public function __construct(
        private CommunicationEventRecorder $events,
    ) {}

    public function registerLiveInbound(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
    ): bool {
        if ($message->direction !== MessageDirection::Inbound
            || (int) $message->conversation_id !== (int) $conversation->id) {
            return false;
        }

        $inserted = CommunicationConversationUnreadMessage::query()
            ->withoutGlobalScopes()
            ->insertOrIgnore([[
                'tenant_id' => $conversation->tenant_id,
                'inbox_id' => $conversation->inbox_id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);
        if ($inserted !== 1) {
            return false;
        }

        $state = $this->lockedState($conversation);
        $this->advanceState($state, 'INBOUND', null, null);
        $this->publish($conversation, $state);

        return true;
    }

    public function markRead(
        CommunicationConversation $conversation,
        int $throughMessageId,
        ?int $actorUserId,
        ?int $actorMembershipId,
    ): CommunicationConversationReadState {
        $message = CommunicationMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->whereKey($throughMessageId)
            ->first();
        if ($message === null) {
            throw (new ModelNotFoundException)->setModel(CommunicationMessage::class, [$throughMessageId]);
        }

        $state = $this->lockedState($conversation);
        $previousThrough = $state->last_read_through_message_id !== null
            ? (int) $state->last_read_through_message_id
            : null;
        $deleted = CommunicationConversationUnreadMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->where('message_id', '<=', $throughMessageId)
            ->delete();
        $cursorAdvanced = $previousThrough === null || $throughMessageId > $previousThrough;
        if ($deleted === 0 && ! $cursorAdvanced) {
            return $state;
        }

        $state->last_read_through_message_id = $previousThrough === null
            ? $throughMessageId
            : max($previousThrough, $throughMessageId);
        $this->advanceState($state, 'READ', $actorUserId, $actorMembershipId);
        $this->publish($conversation, $state);

        return $state;
    }

    public function markUnread(
        CommunicationConversation $conversation,
        int $expectedVersion,
        ?int $actorUserId,
        ?int $actorMembershipId,
    ): CommunicationConversationReadState {
        $state = $this->lockedState($conversation);
        if ((int) $state->version !== $expectedVersion) {
            throw CommunicationConversationApiException::readStateVersionConflict(
                (int) $state->version,
            );
        }

        $hasUnread = CommunicationConversationUnreadMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->exists();
        if ($hasUnread) {
            return $state;
        }

        $latestInbound = CommunicationMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Inbound)
            ->whereNull('purged_at')
            ->whereNull('revoked_at')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
        if ($latestInbound === null) {
            return $state;
        }

        $inserted = CommunicationConversationUnreadMessage::query()
            ->withoutGlobalScopes()
            ->insertOrIgnore([[
                'tenant_id' => $conversation->tenant_id,
                'inbox_id' => $conversation->inbox_id,
                'conversation_id' => $conversation->id,
                'message_id' => $latestInbound->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);
        if ($inserted === 1) {
            $this->advanceState($state, 'UNREAD', $actorUserId, $actorMembershipId);
            $this->publish($conversation, $state);
        }

        return $state;
    }

    public function removePendingMessage(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
        string $action = 'REVOKE',
    ): bool {
        $deleted = CommunicationConversationUnreadMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->where('message_id', $message->id)
            ->delete();
        if ($deleted === 0) {
            return false;
        }

        $state = $this->lockedState($conversation);
        $this->advanceState($state, $action, null, null);
        $this->publish($conversation, $state);

        return true;
    }

    public function movePendingMessage(
        CommunicationConversation $source,
        CommunicationConversation $target,
        CommunicationMessage $message,
    ): bool {
        if ((int) $source->id === (int) $target->id) {
            return false;
        }

        $pending = CommunicationConversationUnreadMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $source->tenant_id)
            ->where('conversation_id', $source->id)
            ->where('message_id', $message->id)
            ->lockForUpdate()
            ->first();
        if ($pending === null) {
            return false;
        }

        $pending->forceFill([
            'inbox_id' => $target->inbox_id,
            'conversation_id' => $target->id,
        ])->save();

        $sourceState = $this->lockedState($source);
        $targetState = $this->lockedState($target);
        $this->advanceState($sourceState, 'MOVE_OUT', null, null);
        $this->advanceState($targetState, 'MOVE_IN', null, null);
        $this->publish($source, $sourceState);
        $this->publish($target, $targetState);

        return true;
    }

    public function purge(CommunicationConversation $conversation): bool
    {
        $pendingCount = CommunicationConversationUnreadMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->count();
        $state = CommunicationConversationReadState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->lockForUpdate()
            ->first();
        if ($pendingCount === 0 && $state === null) {
            return false;
        }

        CommunicationConversationUnreadMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->delete();
        $version = (int) ($state?->version ?? 0) + 1;
        CommunicationConversationReadState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->delete();
        $this->events->record(
            tenantId: (int) $conversation->tenant_id,
            type: 'conversation.read_state.updated',
            payload: [
                'conversation_id' => (int) $conversation->id,
                'inbox_id' => (int) $conversation->inbox_id,
                'unread_count' => 0,
                'first_unread_message_id' => null,
                'last_read_through_message_id' => null,
                'version' => $version,
            ],
            inboxId: (int) $conversation->inbox_id,
            conversationId: (int) $conversation->id,
        );

        return true;
    }

    /**
     * @param  list<CommunicationConversation>  $fragments
     */
    public function mergeFragments(
        CommunicationConversation $survivor,
        array $fragments,
    ): void {
        $donorIds = collect($fragments)
            ->reject(fn (CommunicationConversation $fragment): bool => (int) $fragment->id === (int) $survivor->id)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
        if ($donorIds === []) {
            return;
        }

        // Unique is tenant+message: re-point donor rows in place instead of insert+delete.
        CommunicationConversationUnreadMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $survivor->tenant_id)
            ->whereIn('conversation_id', $donorIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->update([
                'inbox_id' => $survivor->inbox_id,
                'conversation_id' => $survivor->id,
                'updated_at' => now(),
            ]);

        /** @var Collection<int, CommunicationConversationReadState> $states */
        $states = CommunicationConversationReadState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $survivor->tenant_id)
            ->whereIn('conversation_id', [$survivor->id, ...$donorIds])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $state = $states->firstWhere('conversation_id', $survivor->id)
            ?? $this->lockedState($survivor);
        $newest = $states->sortByDesc('updated_at')->first();
        $lastRead = $states->pluck('last_read_through_message_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->max();
        $state->last_read_through_message_id = $lastRead;
        $state->updated_by_user_id = $newest?->updated_by_user_id;
        $state->updated_by_membership_id = $newest?->updated_by_membership_id;
        $state->version = max(0, (int) $states->max('version'));
        $this->advanceState($state, 'MERGE', $state->updated_by_user_id, $state->updated_by_membership_id);

        CommunicationConversationReadState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $survivor->tenant_id)
            ->whereIn('conversation_id', $donorIds)
            ->delete();
        $this->publish($survivor, $state);
    }

    /**
     * @return array{
     *     unread_count:int,
     *     first_unread_message_id:?int,
     *     version:int,
     *     last_read_through_message_id:?int
     * }
     */
    public function snapshot(CommunicationConversation $conversation): array
    {
        $base = CommunicationConversationUnreadMessage::query()
            ->withoutGlobalScopes()
            ->where('communication_conversation_unread_messages.tenant_id', $conversation->tenant_id)
            ->where('communication_conversation_unread_messages.conversation_id', $conversation->id);
        $first = (clone $base)
            ->join('communication_messages', 'communication_messages.id', '=', 'communication_conversation_unread_messages.message_id')
            ->orderBy('communication_messages.occurred_at')
            ->orderBy('communication_messages.id')
            ->value('communication_conversation_unread_messages.message_id');
        $state = CommunicationConversationReadState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->first();

        return [
            'unread_count' => (clone $base)->count(),
            'first_unread_message_id' => $first !== null ? (int) $first : null,
            'version' => (int) ($state?->version ?? 0),
            'last_read_through_message_id' => $state?->last_read_through_message_id !== null
                ? (int) $state->last_read_through_message_id
                : null,
        ];
    }

    public function project(CommunicationConversation $conversation): CommunicationConversation
    {
        $snapshot = $this->snapshot($conversation);
        $conversation->setAttribute('unread_count', $snapshot['unread_count']);
        $conversation->setAttribute('first_unread_message_id', $snapshot['first_unread_message_id']);
        $state = CommunicationConversationReadState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->first();
        $conversation->setRelation('readState', $state);

        return $conversation;
    }

    private function lockedState(
        CommunicationConversation $conversation,
    ): CommunicationConversationReadState {
        $state = CommunicationConversationReadState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->lockForUpdate()
            ->first();
        if ($state !== null) {
            return $state;
        }

        $now = now();
        CommunicationConversationReadState::query()
            ->withoutGlobalScopes()
            ->insertOrIgnore([[
                'tenant_id' => $conversation->tenant_id,
                'inbox_id' => $conversation->inbox_id,
                'conversation_id' => $conversation->id,
                'version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]]);

        return CommunicationConversationReadState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function advanceState(
        CommunicationConversationReadState $state,
        string $action,
        ?int $actorUserId,
        ?int $actorMembershipId,
    ): void {
        $state->forceFill([
            'version' => (int) $state->version + 1,
            'updated_by_user_id' => $actorUserId,
            'updated_by_membership_id' => $actorMembershipId,
            'last_action' => $action,
        ])->save();
    }

    private function publish(
        CommunicationConversation $conversation,
        CommunicationConversationReadState $state,
    ): void {
        $snapshot = $this->snapshot($conversation);
        $this->events->record(
            tenantId: (int) $conversation->tenant_id,
            type: 'conversation.read_state.updated',
            payload: [
                'conversation_id' => (int) $conversation->id,
                'inbox_id' => (int) $conversation->inbox_id,
                'unread_count' => $snapshot['unread_count'],
                'first_unread_message_id' => $snapshot['first_unread_message_id'],
                'last_read_through_message_id' => $snapshot['last_read_through_message_id'],
                'version' => (int) $state->version,
            ],
            inboxId: (int) $conversation->inbox_id,
            conversationId: (int) $conversation->id,
            actorMembershipId: $state->updated_by_membership_id !== null
                ? (int) $state->updated_by_membership_id
                : null,
        );
    }
}
