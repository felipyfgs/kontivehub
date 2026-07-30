<?php

namespace App\Services\Communication;

use App\DTO\Communication\CommunicationContactFiltersData;
use App\Enums\Communication\ProfilePictureState;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class CommunicationContactQuery
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly CommunicationContactCanonicalizer $canonicalizer,
        private readonly WhatsappAddressNormalizer $addressNormalizer,
    ) {}

    /** @return array{conversation_ids:list<int>,visible_inbox_ids:list<int>} */
    public function sharedContentScope(CommunicationContact $contact, User $actor, ?int $inboxId): array
    {
        $visibleInboxIds = $this->access->visibleInboxIds($actor);
        if ($inboxId !== null) {
            if (! in_array($inboxId, $visibleInboxIds, true)) {
                throw (new ModelNotFoundException)->setModel(CommunicationContact::class, [$contact->id]);
            }
            $visibleInboxIds = [$inboxId];
        }
        sort($visibleInboxIds, SORT_NUMERIC);
        $contactIds = $this->canonicalizer->contactIds($contact);
        $identityIds = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $contact->tenant_id)
            ->whereIn('contact_id', $contactIds)
            ->pluck('id');
        $conversationIds = CommunicationConversation::query()->withoutGlobalScopes()
            ->where('tenant_id', $contact->tenant_id)
            ->whereIn('inbox_id', $visibleInboxIds)
            ->whereIn('identity_id', $identityIds)
            ->whereNull('purged_at')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return ['conversation_ids' => $conversationIds, 'visible_inbox_ids' => $visibleInboxIds];
    }

    /** @return LengthAwarePaginator<int, CommunicationContact> */
    public function paginate(CommunicationContactFiltersData $filters, User $actor): LengthAwarePaginator
    {
        $query = CommunicationContact::query()->with([
            'identities.clientLinks.client',
            'identities.clientLinks.clientContact',
        ])->whereNull('merged_into_contact_id');
        $this->withProfilePictureProjection($query, $actor);

        if ($filters->search !== null && $filters->search !== '') {
            $needle = '%'.mb_strtolower($filters->search).'%';
            $addressHash = $filters->phoneSearch
                ? $this->normalizedAddressHash($filters->search)
                : null;
            $query->where(fn ($builder) => $builder
                ->whereRaw("LOWER(COALESCE(name, '')) LIKE ?", [$needle])
                ->orWhereHas(
                    'identities',
                    fn ($identities) => $identities
                        ->where('address_masked', 'like', '%'.$filters->search.'%'),
                )
                ->when($addressHash !== null, fn ($contacts) => $contacts->orWhereHas(
                    'identities',
                    fn ($identities) => $identities->where('address_hash', $addressHash),
                )));
        }

        if ($filters->isActive !== null) {
            $query->where('is_active', $filters->isActive);
        } elseif (! $filters->includeInactive) {
            $query->where('is_active', true);
        }

        if ($filters->isProvisional !== null) {
            $query->where('is_provisional', $filters->isProvisional);
        }
        if ($filters->linked !== null) {
            $filters->linked
                ? $query->whereHas('identities.clientLinks')
                : $query->whereDoesntHave('identities.clientLinks');
        }

        $this->applySort($query, $filters->sort, $filters->direction);

        return $query->paginate(
            perPage: $filters->perPage,
            page: $filters->page,
        );
    }

    /** Apply one correlated projection for both list and detail resources. */
    public function withProfilePictureProjection(Builder $query, User $actor): Builder
    {
        $visible = $this->access->visibleInboxIds($actor);
        if ($visible === []) {
            return $query;
        }
        $picture = DB::table('communication_inbox_identity_profiles as p')
            ->select([
                'p.id as profile_id',
                'p.profile_picture_version',
                'p.profile_picture_state',
            ])
            ->join('communication_identities as pi', 'pi.id', '=', 'p.identity_id')
            ->whereColumn('pi.contact_id', 'communication_contacts.id')
            ->whereColumn('p.tenant_id', 'communication_contacts.tenant_id')
            ->whereColumn('pi.tenant_id', 'communication_contacts.tenant_id')
            ->whereIn('p.inbox_id', $visible)
            ->whereNull('pi.canonical_identity_id')
            ->where('pi.is_active', true)
            ->whereNull('pi.purged_at')
            // Prefer a usable private asset. If none exists, expose the most
            // recent real state so the client can render an honest fallback.
            ->orderByRaw(
                'CASE WHEN p.profile_picture_state = ? THEN 0 ELSE 1 END',
                [ProfilePictureState::Ready->value],
            )
            ->orderByRaw('(
                SELECT MAX(c.last_message_at)
                FROM communication_conversations AS c
                JOIN communication_identities AS ci
                  ON ci.id = c.identity_id
                 AND ci.tenant_id = c.tenant_id
                WHERE c.tenant_id = p.tenant_id
                  AND c.inbox_id = p.inbox_id
                  AND COALESCE(ci.canonical_identity_id, ci.id) = COALESCE(pi.canonical_identity_id, pi.id)
                  AND c.purged_at IS NULL
                  AND c.merged_into_conversation_id IS NULL
            ) DESC NULLS LAST')
            ->orderByDesc('p.updated_at')
            ->orderByDesc('p.id')
            ->limit(1);

        return $query
            ->select('communication_contacts.*')
            ->leftJoinLateral($picture, 'profile_picture')
            ->addSelect([
                'profile_picture.profile_id as profile_picture_profile_id',
                'profile_picture.profile_picture_version as profile_picture_version',
                'profile_picture.profile_picture_state as profile_picture_state',
            ]);
    }

    private function normalizedAddressHash(string $search): ?string
    {
        try {
            $normalized = $this->addressNormalizer->normalize($search);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $normalized) === 1
            ? hash('sha256', $normalized)
            : null;
    }

    private function applySort(
        Builder $query,
        string $sort,
        string $direction,
    ): void {
        if (! in_array($sort, ['name', 'id', 'created_at'], true)) {
            $query->orderByRaw('name IS NULL')->orderBy('name')->orderBy('id');

            return;
        }

        if ($sort === 'name') {
            $query->orderByRaw('name IS NULL')
                ->orderBy('name', $direction)
                ->orderBy('id', $direction);

            return;
        }

        $query->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('id', $direction);
        }
    }
}
