<?php

namespace App\Services\Communication\Conversation;

use App\DTO\Communication\CommunicationConversationFiltersData;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\CommunicationConversationCanonicalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class CommunicationConversationQuery
{
    public function __construct(
        private CommunicationAccess $access,
        private CommunicationConversationCanonicalizer $canonicalizer,
    ) {}

    /** @return LengthAwarePaginator<int, CommunicationConversation> */
    public function paginate(
        User $actor,
        CommunicationConversationFiltersData $filters,
    ): LengthAwarePaginator {
        $inboxIds = $this->access->visibleInboxIds($actor);
        $query = $this->withUnreadProjection(CommunicationConversation::query())
            ->whereIn('inbox_id', $inboxIds)
            ->whereNull('merged_into_conversation_id')
            ->with($this->workspaceRelations())
            ->withCount('messages');

        if ($filters->inboxId !== null) {
            $query->where('inbox_id', $filters->inboxId);
        }
        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }
        if ($filters->assigneeMembershipId !== null) {
            $query->where('assignee_membership_id', $filters->assigneeMembershipId);
        }
        if ($filters->workDepartmentId !== null) {
            $query->where('work_department_id', $filters->workDepartmentId);
        }
        if ($filters->unassigned) {
            $query->whereNull('assignee_membership_id');
        }
        if ($filters->unreadOnly) {
            $query->whereHas('unreadMessages');
        }
        if ($filters->search !== null) {
            $this->applySearch($query, $inboxIds, $filters->search);
        }

        return $query
            ->orderByDesc('priority')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(
                perPage: $filters->perPage,
                page: $filters->page,
            );
    }

    public function detail(
        CommunicationConversation $conversation,
        bool $includeMessages = true,
    ): CommunicationConversation {
        $canonical = $this->canonicalizer->conversation($conversation);
        $relations = $this->workspaceRelations();
        if ($includeMessages) {
            $relations[] = 'messages.attachments';
        }

        return $this->withUnreadProjection(CommunicationConversation::query())
            ->whereKey($canonical->id)
            ->with($relations)
            ->withCount('messages')
            ->firstOrFail();
    }

    /** @return list<string> */
    private function workspaceRelations(): array
    {
        return [
            'identity.contact',
            'identity.clientLinks.clientContact',
            'identity.inboxProfiles',
            'identity.canonicalIdentity.contact',
            'identity.canonicalIdentity.clientLinks.clientContact',
            'identity.canonicalIdentity.inboxProfiles',
            'clients',
            'labels',
            'latestMessage.attachments',
            'readState',
        ];
    }

    /** @param Builder<CommunicationConversation> $query */
    private function withUnreadProjection(Builder $query): Builder
    {
        return $query
            ->withCount('unreadMessages as unread_count')
            ->addSelect([
                'first_unread_message_id' => DB::table('communication_conversation_unread_messages as unread')
                    ->select('unread.message_id')
                    ->join('communication_messages as unread_message', 'unread_message.id', '=', 'unread.message_id')
                    ->whereColumn('unread.tenant_id', 'communication_conversations.tenant_id')
                    ->whereColumn('unread.conversation_id', 'communication_conversations.id')
                    ->orderBy('unread_message.occurred_at')
                    ->orderBy('unread_message.id')
                    ->limit(1),
            ]);
    }

    /**
     * @param  Builder<CommunicationConversation>  $query
     * @param  list<int>  $inboxIds
     */
    private function applySearch(
        Builder $query,
        array $inboxIds,
        string $search,
    ): void {
        $normalizedSearch = mb_strtolower($search);
        $messageConversationIds = CommunicationMessage::query()
            ->whereIn('inbox_id', $inboxIds)
            ->whereNull('purged_at')
            ->whereNull('revoked_at')
            ->latest('id')
            ->limit(500)
            ->get(['id', 'conversation_id', 'body_encrypted'])
            ->filter(static fn (CommunicationMessage $message): bool => str_contains(
                mb_strtolower((string) $message->body_encrypted),
                $normalizedSearch,
            ))
            ->pluck('conversation_id')
            ->all();
        $needle = '%'.$normalizedSearch.'%';

        $query->where(static fn (Builder $builder) => $builder
            ->whereIn('id', $messageConversationIds)
            ->orWhereHas(
                'identity.contact',
                static fn (Builder $contacts) => $contacts
                    ->whereRaw("LOWER(COALESCE(name, '')) LIKE ?", [$needle]),
            )
            ->orWhereHas(
                'identity',
                static fn (Builder $identities) => $identities
                    ->where('address_masked', 'like', '%'.$search.'%'),
            )
            ->orWhereHas(
                'clients',
                static fn (Builder $clients) => $clients->where(
                    static fn (Builder $clientNames) => $clientNames
                        ->whereRaw("LOWER(COALESCE(display_name, '')) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(legal_name, '')) LIKE ?", [$needle]),
                ),
            ));
    }
}
