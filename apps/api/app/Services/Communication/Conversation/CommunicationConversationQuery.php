<?php

namespace App\Services\Communication\Conversation;

use App\DTO\Communication\CommunicationConversationFiltersData;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class CommunicationConversationQuery
{
    public function __construct(
        private CommunicationAccess $access,
    ) {}

    /** @return LengthAwarePaginator<int, CommunicationConversation> */
    public function paginate(
        User $actor,
        CommunicationConversationFiltersData $filters,
    ): LengthAwarePaginator {
        $inboxIds = $this->access->visibleInboxIds($actor);
        $query = CommunicationConversation::query()
            ->whereIn('inbox_id', $inboxIds)
            ->with(['identity.contact', 'clients', 'labels', 'latestMessage.attachments'])
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
    ): CommunicationConversation {
        return $conversation->load([
            'identity.contact',
            'clients',
            'labels',
            'messages.attachments',
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
