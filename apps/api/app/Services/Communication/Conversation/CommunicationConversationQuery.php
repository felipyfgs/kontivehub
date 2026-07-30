<?php

namespace App\Services\Communication\Conversation;

use App\DTO\Communication\CommunicationConversationFiltersData;
use App\Enums\Communication\ConversationListSort;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationLabel;
use App\Models\CommunicationMessage;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\CommunicationConversationCanonicalizer;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class CommunicationConversationQuery
{
    public function __construct(
        private CommunicationAccess $access,
        private CommunicationConversationCanonicalizer $canonicalizer,
        private CurrentTenant $currentTenant,
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
        if ($filters->contactId !== null) {
            $this->applyContactFilter($query, $filters->contactId);
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
        if ($filters->labelIds !== []) {
            $this->applyLabelFilter($query, $filters->labelIds);
        }

        $this->applySort($query, $filters->sortBy);

        return $query->paginate(
            perPage: $filters->perPage,
            page: $filters->page,
        );
    }

    /** @param Builder<CommunicationConversation> $query */
    private function applyContactFilter(Builder $query, int $contactId): void
    {
        $tenantId = $this->currentTenant->id();
        if ($tenantId === null) {
            $query->whereRaw('0 = 1');

            return;
        }
        $current = CommunicationContact::query()
            ->select(['id', 'merged_into_contact_id'])
            ->where('tenant_id', $tenantId)
            ->find($contactId);

        if ($current === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        $seen = [];
        while ($current->merged_into_contact_id !== null) {
            if (isset($seen[$current->id]) || count($seen) >= 20) {
                $query->whereRaw('0 = 1');

                return;
            }
            $seen[$current->id] = true;
            $current = CommunicationContact::query()
                ->select(['id', 'merged_into_contact_id'])
                ->where('tenant_id', $tenantId)
                ->find($current->merged_into_contact_id);
            if ($current === null) {
                $query->whereRaw('0 = 1');

                return;
            }
        }

        $canonicalId = (int) $current->id;
        $table = (new CommunicationContact)->getTable();
        $contactIds = collect(DB::select(<<<SQL
            WITH RECURSIVE donors AS (
                SELECT id, merged_into_contact_id, ARRAY[id] AS path, 0 AS depth
                FROM {$table}
                WHERE tenant_id = ? AND id = ?
                UNION ALL
                SELECT contact.id, contact.merged_into_contact_id, donors.path || contact.id, donors.depth + 1
                FROM {$table} AS contact
                INNER JOIN donors ON contact.merged_into_contact_id = donors.id
                WHERE contact.tenant_id = ?
                  AND donors.depth < 20
                  AND NOT contact.id = ANY(donors.path)
            )
            SELECT id FROM donors
            SQL, [$tenantId, $canonicalId, $tenantId]))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($contactIds === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereHas(
            'identity',
            static fn (Builder $identities) => $identities->whereIn('contact_id', $contactIds),
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
        // Projeções vivas a partir do ledger — nunca colunas denormalizadas na linha.
        return $query
            ->withCount('unreadMessages as unread_count')
            ->addSelect([
                'first_unread_message_id' => DB::table('communication_conversation_unread_messages as unread')
                    ->select('unread.message_id')
                    ->join('communication_messages as unread_message', 'unread_message.id', '=', 'unread.message_id')
                    ->whereColumn('unread.tenant_id', 'communication_conversations.tenant_id')
                    ->whereColumn('unread.conversation_id', 'communication_conversations.id')
                    ->whereNull('unread_message.quarantined_at')
                    ->orderBy('unread_message.occurred_at')
                    ->orderBy('unread_message.id')
                    ->limit(1),
            ]);
    }

    /**
     * @param  Builder<CommunicationConversation>  $query
     * @param  list<int>  $labelIds
     */
    private function applyLabelFilter(Builder $query, array $labelIds): void
    {
        $validLabelIds = CommunicationLabel::query()
            ->whereIn('id', $labelIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($validLabelIds === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereHas(
            'labels',
            static fn (Builder $labels) => $labels->whereIn('communication_labels.id', $validLabelIds),
        );
    }

    /**
     * Ordenação allowlisted com colunas qualificadas e desempate por id.
     *
     * - sort_by ausente: preserva o default legado (priority DESC → last_message_at → id).
     * - Preferência da SPA: last_activity_desc (enviado explicitamente).
     * - unread_desc: ledger de não lidas (subquery), não coluna física.
     * - last_message_at: NULLS LAST para não empurrar filas sem mensagem ao topo em DESC.
     *
     * @param  Builder<CommunicationConversation>  $query
     */
    private function applySort(Builder $query, ?ConversationListSort $sortBy): void
    {
        $table = $query->getModel()->getTable();

        if ($sortBy === null) {
            $query
                ->orderByDesc("{$table}.priority")
                ->orderByRaw("{$table}.last_message_at DESC NULLS LAST")
                ->orderByDesc("{$table}.id");

            return;
        }

        match ($sortBy) {
            ConversationListSort::LastActivityDesc => $query
                ->orderByRaw("{$table}.last_message_at DESC NULLS LAST")
                ->orderByDesc("{$table}.id"),
            ConversationListSort::LastActivityAsc => $query
                ->orderByRaw("{$table}.last_message_at ASC NULLS LAST")
                ->orderBy("{$table}.id"),
            ConversationListSort::CreatedDesc => $query
                ->orderByDesc("{$table}.created_at")
                ->orderByDesc("{$table}.id"),
            ConversationListSort::CreatedAsc => $query
                ->orderBy("{$table}.created_at")
                ->orderBy("{$table}.id"),
            ConversationListSort::UnreadDesc => $query
                ->orderByDesc(DB::raw('(
                    select count(*)
                    from communication_conversation_unread_messages as sort_unread
                    inner join communication_messages as sort_unread_message
                      on sort_unread_message.id = sort_unread.message_id
                    where sort_unread.conversation_id = '.$table.'.id
                      and sort_unread.tenant_id = '.$table.'.tenant_id
                      and sort_unread_message.quarantined_at is null
                )'))
                ->orderByRaw("{$table}.last_message_at DESC NULLS LAST")
                ->orderByDesc("{$table}.id"),
            ConversationListSort::PriorityDesc => $query
                ->orderByDesc("{$table}.priority")
                ->orderByRaw("{$table}.last_message_at DESC NULLS LAST")
                ->orderByDesc("{$table}.id"),
            ConversationListSort::PriorityAsc => $query
                ->orderBy("{$table}.priority")
                ->orderByRaw("{$table}.last_message_at DESC NULLS LAST")
                ->orderByDesc("{$table}.id"),
        };
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
            ->visibleToWorkspace()
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
