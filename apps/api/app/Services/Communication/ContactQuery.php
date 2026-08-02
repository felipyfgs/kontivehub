<?php

namespace App\Services\Communication;

use App\DTO\Communication\ContactFiltersData;
use App\Enums\Communication\ProfilePictureState;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ContactQuery
{
    public function __construct(
        private readonly Access $access,
        private readonly ContactCanonicalizer $canonicalizer,
        private readonly WhatsAppAddressNormalizer $addressNormalizer,
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
    public function paginate(ContactFiltersData $filters, User $actor): LengthAwarePaginator
    {
        $query = CommunicationContact::query()->with([
            'identities.clientLinks.client',
            'identities.clientLinks.clientContact',
        ])->whereNull('merged_into_contact_id');
        $inboxScoped = $filters->inboxId !== null;
        if ($inboxScoped) {
            $this->withInboxContextProjection($query, $actor, $filters->inboxId);
        } else {
            $this->withProfilePictureProjection($query, $actor);
        }

        if ($filters->search !== null && $filters->search !== '') {
            $needle = '%'.mb_strtolower($filters->search).'%';
            $addressHash = $filters->phoneSearch
                ? $this->normalizedAddressHash($filters->search)
                : null;
            $query->where(fn ($builder) => $builder
                ->whereRaw(
                    $inboxScoped
                        ? 'LOWER('.$this->displayNameSql().') LIKE ?'
                        : "LOWER(COALESCE(communication_contacts.name, '')) LIKE ?",
                    [$needle],
                )
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

        $this->applySort($query, $filters->sort, $filters->direction, $inboxScoped);

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

    /**
     * Apply one deterministic, inbox-scoped projection before pagination.
     *
     * @param  Builder<CommunicationContact>  $query
     */
    public function withInboxContextProjection(
        Builder $query,
        User $actor,
        int $inboxId,
    ): Builder {
        if (! in_array($inboxId, $this->access->visibleInboxIds($actor), true)) {
            return $query->whereRaw('1 = 0');
        }

        $context = DB::table('communication_conversations as context_conversation')
            ->select([
                'context_conversation.inbox_id as display_name_inbox_id',
                'context_conversation.id as representative_conversation_id',
                'context_identity.address_masked as representative_address_masked',
                'context_canonical_identity.id as canonical_identity_id',
                'context_profile.id as profile_picture_profile_id',
                'context_profile.profile_picture_version',
                'context_profile.profile_picture_state',
                'context_profile.inbox_id as profile_picture_inbox_id',
                'context_profile.address_book_first_name',
                'context_profile.address_book_full_name',
                'context_profile.verified_name',
                'context_profile.business_name',
                'context_profile.push_name',
            ])
            ->selectRaw('(
                SELECT CASE
                    WHEN COUNT(DISTINCT LOWER(BTRIM(context_client_contact.name))) = 1
                    THEN MIN(BTRIM(context_client_contact.name))
                    ELSE NULL
                END
                FROM communication_identity_links AS context_link
                INNER JOIN client_contacts AS context_client_contact
                    ON context_client_contact.id = context_link.client_contact_id
                   AND context_client_contact.tenant_id = context_link.tenant_id
                WHERE context_link.tenant_id = context_conversation.tenant_id
                  AND context_link.identity_id = context_canonical_identity.id
                  AND context_client_contact.is_active = TRUE
                  AND NULLIF(BTRIM(context_client_contact.name), \'\') IS NOT NULL
            ) AS linked_client_contact_name')
            ->join('communication_identities as context_identity', function ($join): void {
                $join->on('context_identity.id', '=', 'context_conversation.identity_id')
                    ->on('context_identity.tenant_id', '=', 'context_conversation.tenant_id');
            })
            ->join('communication_identities as context_canonical_identity', function ($join): void {
                $join->on(
                    'context_canonical_identity.id',
                    '=',
                    DB::raw('COALESCE(context_identity.canonical_identity_id, context_identity.id)'),
                )->on('context_canonical_identity.tenant_id', '=', 'context_conversation.tenant_id');
            })
            ->leftJoin('communication_inbox_identity_profiles as context_profile', function ($join) use ($inboxId): void {
                $join->on('context_profile.identity_id', '=', 'context_canonical_identity.id')
                    ->on('context_profile.tenant_id', '=', 'context_conversation.tenant_id')
                    ->where('context_profile.inbox_id', '=', $inboxId);
            })
            ->whereColumn('context_conversation.tenant_id', 'communication_contacts.tenant_id')
            ->whereColumn('context_canonical_identity.contact_id', 'communication_contacts.id')
            ->where('context_conversation.inbox_id', $inboxId)
            ->whereNull('context_conversation.purged_at')
            ->whereNull('context_conversation.merged_into_conversation_id')
            ->where('context_canonical_identity.is_active', true)
            ->whereNull('context_canonical_identity.purged_at')
            ->orderByRaw('context_conversation.last_message_at DESC NULLS LAST')
            ->orderByDesc('context_conversation.id')
            ->limit(1);

        $displayName = $this->displayNameSql();

        return $query
            ->select('communication_contacts.*')
            ->joinLateral($context, 'contact_context')
            ->addSelect([
                'contact_context.display_name_inbox_id',
                'contact_context.profile_picture_profile_id',
                'contact_context.profile_picture_version',
                'contact_context.profile_picture_state',
                'contact_context.profile_picture_inbox_id',
            ])
            ->selectRaw($displayName.' AS display_name')
            ->selectRaw(
                $this->displayNameSourceSql().' AS display_name_source',
                ['LEGACY_PROVISIONAL'],
            )
            ->selectRaw($this->displayNameStateSql().' AS display_name_state');
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
        bool $inboxScoped,
    ): void {
        $name = $inboxScoped ? $this->displayNameSql() : 'communication_contacts.name';
        if (! in_array($sort, ['name', 'id', 'created_at'], true)) {
            $query->orderByRaw("({$name}) IS NULL")
                ->orderByRaw("{$name} ASC")
                ->orderBy('communication_contacts.id');

            return;
        }

        if ($sort === 'name') {
            $query->orderByRaw("({$name}) IS NULL")
                ->orderByRaw("{$name} {$direction}")
                ->orderBy('communication_contacts.id', $direction);

            return;
        }

        $query->orderBy('communication_contacts.'.$sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('communication_contacts.id', $direction);
        }
    }

    private function displayNameSql(): string
    {
        return <<<'SQL'
COALESCE(
    CASE
        WHEN communication_contacts.is_provisional = FALSE
        THEN NULLIF(BTRIM(communication_contacts.name), '')
        ELSE NULL
    END,
    NULLIF(BTRIM(contact_context.linked_client_contact_name), ''),
    NULLIF(BTRIM(contact_context.address_book_full_name), ''),
    NULLIF(BTRIM(contact_context.address_book_first_name), ''),
    NULLIF(BTRIM(contact_context.verified_name), ''),
    NULLIF(BTRIM(contact_context.business_name), ''),
    NULLIF(BTRIM(contact_context.push_name), ''),
    CASE
        WHEN communication_contacts.is_provisional = TRUE
        THEN NULLIF(BTRIM(communication_contacts.name), '')
        ELSE NULL
    END,
    NULLIF(BTRIM(contact_context.representative_address_masked), ''),
    CASE
        WHEN communication_contacts.is_provisional = TRUE
        THEN CONCAT('Provisório #', communication_contacts.id)
        ELSE CONCAT('Contato #', communication_contacts.id)
    END
)
SQL;
    }

    private function displayNameSourceSql(): string
    {
        return <<<'SQL'
CASE
    WHEN communication_contacts.is_provisional = FALSE
         AND NULLIF(BTRIM(communication_contacts.name), '') IS NOT NULL
        THEN 'MANUAL_CONTACT'
    WHEN NULLIF(BTRIM(contact_context.linked_client_contact_name), '') IS NOT NULL
        THEN 'CLIENT_CONTACT'
    WHEN NULLIF(BTRIM(contact_context.address_book_full_name), '') IS NOT NULL
         OR NULLIF(BTRIM(contact_context.address_book_first_name), '') IS NOT NULL
        THEN 'WHATSAPP_ADDRESS_BOOK'
    WHEN NULLIF(BTRIM(contact_context.verified_name), '') IS NOT NULL
        THEN 'WHATSAPP_USER_INFO'
    WHEN NULLIF(BTRIM(contact_context.business_name), '') IS NOT NULL
        THEN 'WHATSAPP_BUSINESS'
    WHEN NULLIF(BTRIM(contact_context.push_name), '') IS NOT NULL
        THEN 'WHATSAPP_PUSH_NAME'
    WHEN communication_contacts.is_provisional = TRUE
         AND NULLIF(BTRIM(communication_contacts.name), '') IS NOT NULL
        THEN ?
    WHEN NULLIF(BTRIM(contact_context.representative_address_masked), '') IS NOT NULL
        THEN 'MASKED_ADDRESS'
    ELSE 'OPAQUE_ID'
END
SQL;
    }

    private function displayNameStateSql(): string
    {
        return <<<'SQL'
CASE
    WHEN communication_contacts.is_provisional = FALSE
         AND NULLIF(BTRIM(communication_contacts.name), '') IS NOT NULL
        THEN 'CURATED'
    WHEN NULLIF(BTRIM(contact_context.linked_client_contact_name), '') IS NOT NULL
        THEN 'CURATED'
    WHEN NULLIF(BTRIM(contact_context.address_book_full_name), '') IS NOT NULL
         OR NULLIF(BTRIM(contact_context.address_book_first_name), '') IS NOT NULL
         OR NULLIF(BTRIM(contact_context.verified_name), '') IS NOT NULL
         OR NULLIF(BTRIM(contact_context.business_name), '') IS NOT NULL
         OR NULLIF(BTRIM(contact_context.push_name), '') IS NOT NULL
         OR (
             communication_contacts.is_provisional = TRUE
             AND NULLIF(BTRIM(communication_contacts.name), '') IS NOT NULL
         )
        THEN 'OBSERVED'
    ELSE 'FALLBACK'
END
SQL;
    }
}
