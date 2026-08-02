<?php

namespace App\Services\Communication;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\FlowRunStatus;
use App\Enums\CommunicationChannel;
use App\Exceptions\WhatsAppPeerCorrelationConflictException;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationFlowRun;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationMessage;
use App\Services\Communication\Contact\InboxIdentityProfileReconciliationScheduler;
use App\Services\Communication\ProfilePicture\ProfilePictureRefreshScheduler;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * Correlaciona aliases 1:1 comprovados pelo gateway dentro da transação do evento.
 */
final readonly class WhatsAppPeerCorrelationService
{
    public function __construct(
        private WhatsAppAddressNormalizer $normalizer,
        private Contact\InboxIdentityProfileMerger $identityProfiles,
        private Conversation\ConversationReadStateService $readState,
        private ProfilePictureRefreshScheduler $profilePictures,
        private InboxIdentityProfileReconciliationScheduler $identityProfileReconciliation,
    ) {}

    /**
     * @param  list<string>  $aliases
     * @return array{0:CommunicationIdentity,1:CommunicationConversation}
     */
    public function correlate(
        CommunicationInbox $inbox,
        string $canonicalAddress,
        array $aliases,
        bool $history,
        DateTimeInterface $occurredAt,
        ?CommunicationMessage $existingMessage = null,
    ): array {
        $aliases = array_values(array_unique([...$aliases, $canonicalAddress]));
        sort($aliases, SORT_STRING);

        $this->lockAliases($inbox, $aliases);
        [$identity, $identityIds] = $this->correlateIdentities(
            $inbox,
            $canonicalAddress,
            $aliases,
            $occurredAt,
        );
        $this->closeSelfConversations($inbox, $occurredAt);
        $conversation = $this->correlateConversation(
            $inbox,
            $identity,
            $identityIds,
            $history,
            $occurredAt,
            $existingMessage,
        );
        $this->profilePictures->schedule($inbox, $identity);
        $this->identityProfileReconciliation->schedule($inbox, $identity);

        return [$identity, $conversation];
    }

    /**
     * @param  list<string>  $aliases
     */
    private function lockAliases(CommunicationInbox $inbox, array $aliases): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A correlação WhatsApp exige uma transação ativa.');
        }

        foreach ($aliases as $alias) {
            // A identity é única por tenant/canal/endereço, não por inbox.
            // O lock precisa usar o mesmo escopo para cobrir inboxes paralelas.
            $bytes = substr(hash(
                'sha256',
                $inbox->tenant_id.'|'.CommunicationChannel::WhatsApp->value.'|'.$alias,
                true,
            ), 0, 8);
            /** @var array{scope:int,alias:int} $parts */
            $parts = unpack('Nscope/Nalias', $bytes);
            DB::select(
                'SELECT pg_advisory_xact_lock(?, ?)',
                [$this->signedInt32($parts['scope']), $this->signedInt32($parts['alias'])],
            );
        }
    }

    /**
     * @param  list<string>  $aliases
     * @return array{0:CommunicationIdentity,1:list<int>}
     */
    private function correlateIdentities(
        CommunicationInbox $inbox,
        string $canonicalAddress,
        array $aliases,
        DateTimeInterface $seenAt,
    ): array {
        $hashes = array_map(
            static fn (string $alias): string => hash('sha256', $alias),
            $aliases,
        );
        $seed = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('channel', CommunicationChannel::WhatsApp->value)
            ->whereIn('address_hash', $hashes)
            ->orderBy('id')
            ->get();
        $identityIds = $this->identityEquivalenceIds($inbox, $seed);
        $this->lockIdentityClasses($inbox, $identityIds);
        $seed = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('channel', CommunicationChannel::WhatsApp->value)
            ->whereIn('address_hash', $hashes)
            ->orderBy('id')
            ->get();
        $identityIds = $this->identityEquivalenceIds($inbox, $seed);
        $contactIds = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('channel', CommunicationChannel::WhatsApp->value)
            ->where(static function ($query) use ($hashes, $identityIds): void {
                $query->whereIn('address_hash', $hashes);
                if ($identityIds !== []) {
                    $query->orWhereIn('id', $identityIds);
                }
            })
            ->pluck('contact_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $contacts = $this->lockContactClasses(
            $inbox,
            $contactIds,
            $identityIds,
            $hashes,
        );
        $contactIds = $contacts
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($contacts->contains(
            static fn (CommunicationContact $contact): bool => $contact->purged_at !== null,
        )) {
            throw new LogicException('Contato indisponível durante a correlação.');
        }
        $contactIdentities = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('channel', CommunicationChannel::WhatsApp->value)
            ->whereIn('contact_id', $contactIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $matches = $contactIdentities
            ->filter(static fn (CommunicationIdentity $identity): bool => (
                in_array((string) $identity->address_hash, $hashes, true)
                || in_array((int) $identity->id, $identityIds, true)
            ));

        $canonicalHash = hash('sha256', $canonicalAddress);
        $lockedContactIds = $contacts->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($matches->pluck('contact_id')->map(static fn ($id): int => (int) $id)
            ->diff($lockedContactIds)->isNotEmpty()) {
            throw new LogicException('Contato da identity mudou durante a correlação.');
        }
        $contact = $this->preferredContact($contacts);
        if ($contact === null) {
            $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
                'tenant_id' => $inbox->tenant_id,
                'is_provisional' => true,
                'is_active' => true,
            ]);
        }

        /** @var array<string,CommunicationIdentity> $byHash */
        $byHash = $matches->keyBy('address_hash')->all();
        foreach ($aliases as $alias) {
            $hash = hash('sha256', $alias);
            if (isset($byHash[$hash])) {
                continue;
            }

            $byHash[$hash] = CommunicationIdentity::query()->withoutGlobalScopes()->create([
                'tenant_id' => $inbox->tenant_id,
                'contact_id' => $contact->id,
                'channel' => CommunicationChannel::WhatsApp,
                'address_encrypted' => $alias,
                'address_hash' => $hash,
                'address_masked' => $this->maskAddress($alias),
                'is_active' => true,
                'last_seen_at' => $seenAt,
            ]);
        }

        $canonicalCandidate = $byHash[$canonicalHash];
        $identity = count($aliases) === 1 && $canonicalCandidate->canonical_identity_id !== null
            ? $matches->firstWhere('id', (int) $canonicalCandidate->canonical_identity_id)
            : $this->preferredCanonicalIdentity($byHash, $canonicalCandidate, $seenAt);
        if (! $identity instanceof CommunicationIdentity) {
            throw new LogicException('Identity canônica fora do conjunto correlacionado.');
        }
        $formerCanonicalIdentities = collect($byHash)
            ->filter(static fn (CommunicationIdentity $candidate): bool => (
                (int) $candidate->id !== (int) $identity->id
                && $candidate->canonical_identity_id === null
            ));

        foreach ($byHash as $match) {
            $match->forceFill([
                'contact_id' => $contact->id,
                'canonical_identity_id' => (int) $match->id === (int) $identity->id
                    ? null
                    : $identity->id,
                'last_seen_at' => in_array((string) $match->address_hash, $hashes, true)
                    ? $this->latestDate($match->last_seen_at, $seenAt)
                    : $match->last_seen_at,
            ])->save();
        }
        foreach ($formerCanonicalIdentities as $formerCanonicalIdentity) {
            $this->identityProfiles->mergeFromDonor($identity, $formerCanonicalIdentity);
        }
        foreach ($contactIdentities as $ownedIdentity) {
            if ((int) $ownedIdentity->contact_id === (int) $contact->id) {
                continue;
            }
            $ownedIdentity->forceFill(['contact_id' => $contact->id])->save();
        }
        $this->mergeContacts($contacts, $contact);

        return [
            $identity,
            array_values(array_map(
                static fn (CommunicationIdentity $row): int => (int) $row->id,
                $byHash,
            )),
        ];
    }

    /**
     * @param  Collection<int,CommunicationIdentity>  $seed
     * @return list<int>
     */
    private function identityEquivalenceIds(
        CommunicationInbox $inbox,
        Collection $seed,
    ): array {
        $ids = $seed->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        foreach ($seed->pluck('canonical_identity_id')->filter() as $canonicalId) {
            $ids[] = (int) $canonicalId;
        }
        $ids = array_values(array_unique($ids));

        for ($depth = 0; $depth < 16 && $ids !== []; $depth++) {
            $rows = CommunicationIdentity::query()->withoutGlobalScopes()
                ->where('tenant_id', $inbox->tenant_id)
                ->where('channel', CommunicationChannel::WhatsApp->value)
                ->where(static fn ($query) => $query
                    ->whereIn('id', $ids)
                    ->orWhereIn('canonical_identity_id', $ids))
                ->get(['id', 'canonical_identity_id']);
            $expanded = $ids;
            foreach ($rows as $row) {
                $expanded[] = (int) $row->id;
                if ($row->canonical_identity_id !== null) {
                    $expanded[] = (int) $row->canonical_identity_id;
                }
            }
            $expanded = array_values(array_unique($expanded));
            sort($expanded, SORT_NUMERIC);
            if ($expanded === $ids) {
                return $ids;
            }
            $ids = $expanded;
        }

        if ($ids !== []) {
            throw new LogicException('Classe de identities excede o limite de correlação.');
        }

        return [];
    }

    /**
     * @param  list<int>  $identityIds
     */
    private function lockIdentityClasses(
        CommunicationInbox $inbox,
        array $identityIds,
    ): void {
        if ($identityIds === []) {
            return;
        }
        $lockedIds = [];
        for ($depth = 0; $depth < 16; $depth++) {
            sort($identityIds, SORT_NUMERIC);
            foreach (array_values(array_diff($identityIds, $lockedIds)) as $identityId) {
                $bytes = substr(hash(
                    'sha256',
                    $inbox->tenant_id.'|'.CommunicationChannel::WhatsApp->value.'|member|'.$identityId,
                    true,
                ), 0, 8);
                /** @var array{scope:int,member:int} $parts */
                $parts = unpack('Nscope/Nmember', $bytes);
                DB::select(
                    'SELECT pg_advisory_xact_lock(?, ?)',
                    [$this->signedInt32($parts['scope']), $this->signedInt32($parts['member'])],
                );
            }
            $lockedIds = array_values(array_unique([...$lockedIds, ...$identityIds]));
            sort($lockedIds, SORT_NUMERIC);

            $seed = CommunicationIdentity::query()->withoutGlobalScopes()
                ->where('tenant_id', $inbox->tenant_id)
                ->where('channel', CommunicationChannel::WhatsApp->value)
                ->where(static fn ($query) => $query
                    ->whereIn('id', $lockedIds)
                    ->orWhereIn('canonical_identity_id', $lockedIds))
                ->orderBy('id')
                ->get();
            $expandedIds = $this->identityEquivalenceIds($inbox, $seed);
            if ($expandedIds === $lockedIds) {
                if ($seed->whereNull('canonical_identity_id')->isEmpty()) {
                    throw new LogicException('Classe de identities sem raiz canônica.');
                }

                return;
            }
            $identityIds = array_values(array_unique([...$lockedIds, ...$expandedIds]));
        }

        throw new LogicException('Classe de identities mudou continuamente durante a correlação.');
    }

    /**
     * @param  Collection<int,CommunicationContact>  $contacts
     */
    private function preferredContact(Collection $contacts): ?CommunicationContact
    {
        $roots = $contacts->whereNull('merged_into_contact_id');
        if ($contacts->isNotEmpty() && $roots->isEmpty()) {
            throw new LogicException('Classe de contacts sem destino canônico.');
        }

        /** @var CommunicationContact|null $preferred */
        $preferred = $roots->sort(static function (
            CommunicationContact $left,
            CommunicationContact $right,
        ): int {
            $leftRank = [
                $left->purged_at !== null ? 1 : 0,
                $left->is_provisional ? 1 : 0,
                trim((string) $left->name) === '' ? 1 : 0,
                $left->is_active ? 0 : 1,
                (int) $left->id,
            ];
            $rightRank = [
                $right->purged_at !== null ? 1 : 0,
                $right->is_provisional ? 1 : 0,
                trim((string) $right->name) === '' ? 1 : 0,
                $right->is_active ? 0 : 1,
                (int) $right->id,
            ];

            return $leftRank <=> $rightRank;
        })->first();

        return $preferred;
    }

    /**
     * @param  array<string,CommunicationIdentity>  $byHash
     */
    private function preferredCanonicalIdentity(
        array $byHash,
        CommunicationIdentity $candidate,
        DateTimeInterface $seenAt,
    ): CommunicationIdentity {
        $phoneIdentities = array_values(array_filter(
            $byHash,
            static fn (CommunicationIdentity $identity): bool => str_starts_with(
                (string) $identity->address_encrypted,
                '+',
            ),
        ));
        if ($phoneIdentities === []) {
            return $candidate;
        }

        usort($phoneIdentities, function (
            CommunicationIdentity $left,
            CommunicationIdentity $right,
        ) use ($candidate, $seenAt): int {
            $leftSeen = (int) $left->id === (int) $candidate->id
                ? $this->latestDate($left->last_seen_at, $seenAt)
                : $left->last_seen_at;
            $rightSeen = (int) $right->id === (int) $candidate->id
                ? $this->latestDate($right->last_seen_at, $seenAt)
                : $right->last_seen_at;
            $leftRank = [
                -($leftSeen?->getTimestamp() ?? 0),
                $left->canonical_identity_id === null ? 0 : 1,
                (int) $left->id,
            ];
            $rightRank = [
                -($rightSeen?->getTimestamp() ?? 0),
                $right->canonical_identity_id === null ? 0 : 1,
                (int) $right->id,
            ];

            return $leftRank <=> $rightRank;
        });

        return $phoneIdentities[0];
    }

    /**
     * @param  list<int>  $seedIds
     * @return list<int>
     */
    private function contactEquivalenceIds(
        CommunicationInbox $inbox,
        array $seedIds,
    ): array {
        $ids = array_values(array_unique($seedIds));
        sort($ids, SORT_NUMERIC);

        for ($depth = 0; $depth < 16 && $ids !== []; $depth++) {
            $rows = CommunicationContact::query()->withoutGlobalScopes()
                ->where('tenant_id', $inbox->tenant_id)
                ->where(static fn ($query) => $query
                    ->whereIn('id', $ids)
                    ->orWhereIn('merged_into_contact_id', $ids))
                ->get(['id', 'merged_into_contact_id']);
            $expanded = $ids;
            foreach ($rows as $row) {
                $expanded[] = (int) $row->id;
                if ($row->merged_into_contact_id !== null) {
                    $expanded[] = (int) $row->merged_into_contact_id;
                }
            }
            $expanded = array_values(array_unique($expanded));
            sort($expanded, SORT_NUMERIC);
            if ($expanded === $ids) {
                return $ids;
            }
            $ids = $expanded;
        }

        if ($ids !== []) {
            throw new LogicException('Classe de contacts excede o limite de correlação.');
        }

        return [];
    }

    /**
     * @param  list<int>  $seedContactIds
     * @param  list<int>  $identityIds
     * @param  list<string>  $hashes
     * @return Collection<int,CommunicationContact>
     */
    private function lockContactClasses(
        CommunicationInbox $inbox,
        array $seedContactIds,
        array $identityIds,
        array $hashes,
    ): Collection {
        if ($seedContactIds === []) {
            return collect();
        }

        $ids = $seedContactIds;
        $lockedIds = [];
        for ($depth = 0; $depth < 16; $depth++) {
            $ids = $this->contactEquivalenceIds(
                $inbox,
                array_values(array_unique([...$ids, ...$lockedIds])),
            );
            $newIds = array_values(array_diff($ids, $lockedIds));
            sort($newIds, SORT_NUMERIC);
            if ($newIds !== []) {
                CommunicationContact::query()->withoutGlobalScopes()
                    ->where('tenant_id', $inbox->tenant_id)
                    ->whereIn('id', $newIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $lockedIds = array_values(array_unique([...$lockedIds, ...$newIds]));
                sort($lockedIds, SORT_NUMERIC);
            }

            $currentContactIds = CommunicationIdentity::query()->withoutGlobalScopes()
                ->where('tenant_id', $inbox->tenant_id)
                ->where('channel', CommunicationChannel::WhatsApp->value)
                ->where(static function ($query) use ($hashes, $identityIds): void {
                    $query->whereIn('address_hash', $hashes);
                    if ($identityIds !== []) {
                        $query->orWhereIn('id', $identityIds);
                    }
                })
                ->pluck('contact_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $expandedIds = $this->contactEquivalenceIds(
                $inbox,
                array_values(array_unique([...$lockedIds, ...$currentContactIds])),
            );
            if ($expandedIds !== $lockedIds) {
                $ids = $expandedIds;

                continue;
            }

            return CommunicationContact::query()->withoutGlobalScopes()
                ->where('tenant_id', $inbox->tenant_id)
                ->whereIn('id', $lockedIds)
                ->orderBy('id')
                ->get();
        }

        throw new LogicException('Classe de contacts mudou continuamente durante a correlação.');
    }

    /**
     * @param  Collection<int,CommunicationContact>  $contacts
     */
    private function mergeContacts(
        Collection $contacts,
        CommunicationContact $survivor,
    ): void {
        $metadata = [];
        $name = trim((string) $survivor->name);
        foreach ($contacts->sortBy('id') as $contact) {
            $contactMetadata = is_array($contact->metadata) ? $contact->metadata : [];
            $metadata = array_replace($metadata, $contactMetadata);
            if ($name === '' && trim((string) $contact->name) !== '') {
                $name = trim((string) $contact->name);
            }
        }
        $metadata = array_replace($metadata, is_array($survivor->metadata) ? $survivor->metadata : []);
        $survivor->forceFill([
            'merged_into_contact_id' => null,
            'name' => $name !== '' ? $name : null,
            'is_provisional' => $name === '' && $survivor->is_provisional,
            'is_active' => $survivor->purged_at === null,
            'metadata' => $metadata !== [] ? $metadata : null,
        ])->save();

        foreach ($contacts as $contact) {
            if ((int) $contact->id === (int) $survivor->id) {
                continue;
            }
            $contact->forceFill([
                'merged_into_contact_id' => $survivor->id,
                'name' => null,
                'is_provisional' => true,
                'is_active' => false,
                'metadata' => null,
            ])->save();
        }
    }

    private function closeSelfConversations(
        CommunicationInbox $inbox,
        DateTimeInterface $occurredAt,
    ): void {
        $raw = trim((string) ($inbox->address_encrypted ?? ''));
        if ($raw === '') {
            return;
        }

        try {
            $sessionAddress = $this->normalizer->normalize($raw);
        } catch (InvalidArgumentException) {
            return;
        }

        $identityId = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('channel', CommunicationChannel::WhatsApp->value)
            ->where('address_hash', hash('sha256', $sessionAddress))
            ->value('id');
        if ($identityId === null) {
            return;
        }

        CommunicationConversation::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('inbox_id', $inbox->id)
            ->where('identity_id', $identityId)
            ->where('status', '<>', ConversationStatus::Resolved->value)
            ->lockForUpdate()
            ->get()
            ->each(static function (CommunicationConversation $conversation) use ($occurredAt): void {
                $conversation->forceFill([
                    'status' => ConversationStatus::Resolved,
                    'resolved_at' => $occurredAt,
                    'assignee_membership_id' => null,
                    'snoozed_until' => null,
                    'lock_version' => (int) $conversation->lock_version + 1,
                ])->save();
            });
    }

    /**
     * @param  list<int>  $identityIds
     */
    private function correlateConversation(
        CommunicationInbox $inbox,
        CommunicationIdentity $identity,
        array $identityIds,
        bool $history,
        DateTimeInterface $occurredAt,
        ?CommunicationMessage $existingMessage,
    ): CommunicationConversation {
        $conversationIds = $this->conversationEquivalenceIds($inbox, $identityIds);
        $conversations = CommunicationConversation::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('inbox_id', $inbox->id)
            ->whereIn('id', $conversationIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $candidates = $conversations->whereNull('merged_into_conversation_id');
        $activeRuns = CommunicationFlowRun::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->whereIn('conversation_id', $candidates->pluck('id')->all())
            ->whereIn('status', FlowRunStatus::nonTerminalValues())
            ->orderBy('conversation_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $runConversationIds = $activeRuns
            ->pluck('conversation_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
        if ($runConversationIds->count() > 1) {
            Log::warning('whatsapp_peer_correlation_flow_conflict', [
                'code' => 'PEER_CORRELATION_CONFLICT',
                'tenant_id' => (int) $inbox->tenant_id,
                'inbox_id' => (int) $inbox->id,
                'conversation_ids' => $runConversationIds->all(),
            ]);
            throw new WhatsAppPeerCorrelationConflictException;
        }

        /** @var CommunicationConversation|null $conversation */
        $conversation = $runConversationIds->isNotEmpty()
            ? $candidates->firstWhere('id', $runConversationIds->first())
            : $candidates->sort(static function (
                CommunicationConversation $left,
                CommunicationConversation $right,
            ): int {
                $leftRank = [
                    $left->status === ConversationStatus::Resolved ? 1 : 0,
                    -($left->last_message_at?->getTimestamp() ?? 0),
                    -(int) $left->id,
                ];
                $rightRank = [
                    $right->status === ConversationStatus::Resolved ? 1 : 0,
                    -($right->last_message_at?->getTimestamp() ?? 0),
                    -(int) $right->id,
                ];

                return $leftRank <=> $rightRank;
            })->first();
        if ($conversation === null) {
            $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
                'tenant_id' => $inbox->tenant_id,
                'inbox_id' => $inbox->id,
                'identity_id' => $identity->id,
                'status' => $history ? ConversationStatus::Resolved : ConversationStatus::Open,
                'work_department_id' => $inbox->work_department_id,
                'last_message_at' => $occurredAt,
                'resolved_at' => $history ? $occurredAt : null,
            ]);
        }

        $activeDonors = $conversations
            ->filter(static fn (CommunicationConversation $candidate): bool => (
                (int) $candidate->id !== (int) $conversation->id
                && $candidate->status !== ConversationStatus::Resolved
                && $candidate->merged_into_conversation_id === null
            ));
        $merged = $this->mergeActiveConversations(
            $inbox,
            $conversation,
            $activeDonors,
            $occurredAt,
        );
        $redirected = $this->compressConversationRedirects(
            $conversations,
            $conversation,
        );
        $this->moveCorrelatedExistingMessage(
            $inbox,
            $existingMessage,
            $conversation,
            $conversations,
        );

        $updates = [
            'identity_id' => $identity->id,
            'last_message_at' => $this->latestDate($conversation->last_message_at, $occurredAt),
        ];
        if (! $history && $conversation->status === ConversationStatus::Resolved) {
            $updates['status'] = ConversationStatus::Open;
            $updates['resolved_at'] = null;
        }
        $conversation->forceFill($updates);
        if ($merged || $redirected || $conversation->isDirty()) {
            $conversation->lock_version = (int) $conversation->lock_version + 1;
            $conversation->save();
        }
        $this->attachIdentityClients($inbox, $conversation, $identityIds);

        return $conversation;
    }

    /**
     * @param  list<int>  $identityIds
     * @return list<int>
     */
    private function conversationEquivalenceIds(
        CommunicationInbox $inbox,
        array $identityIds,
    ): array {
        $rows = CommunicationConversation::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('inbox_id', $inbox->id)
            ->whereIn('identity_id', $identityIds)
            ->get(['id', 'merged_into_conversation_id']);
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
            if ($row->merged_into_conversation_id !== null) {
                $ids[] = (int) $row->merged_into_conversation_id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        for ($depth = 0; $depth < 16 && $ids !== []; $depth++) {
            $expandedRows = CommunicationConversation::query()->withoutGlobalScopes()
                ->where('tenant_id', $inbox->tenant_id)
                ->where('inbox_id', $inbox->id)
                ->where(static fn ($query) => $query
                    ->whereIn('id', $ids)
                    ->orWhereIn('merged_into_conversation_id', $ids))
                ->get(['id', 'merged_into_conversation_id']);
            $expanded = $ids;
            foreach ($expandedRows as $row) {
                $expanded[] = (int) $row->id;
                if ($row->merged_into_conversation_id !== null) {
                    $expanded[] = (int) $row->merged_into_conversation_id;
                }
            }
            $expanded = array_values(array_unique($expanded));
            sort($expanded, SORT_NUMERIC);
            if ($expanded === $ids) {
                return $ids;
            }
            $ids = $expanded;
        }

        if ($ids !== []) {
            throw new LogicException('Classe de conversations excede o limite de correlação.');
        }

        return [];
    }

    /**
     * @param  Collection<int,CommunicationConversation>  $conversations
     */
    private function compressConversationRedirects(
        Collection $conversations,
        CommunicationConversation $survivor,
    ): bool {
        $redirected = false;
        foreach ($conversations as $conversation) {
            if ((int) $conversation->id === (int) $survivor->id
                || $conversation->merged_into_conversation_id === null
                || (int) $conversation->merged_into_conversation_id === (int) $survivor->id) {
                continue;
            }
            $redirected = true;
            $conversation->forceFill([
                'status' => ConversationStatus::Resolved,
                'merged_into_conversation_id' => $survivor->id,
                'snoozed_until' => null,
                'lock_version' => (int) $conversation->lock_version + 1,
            ])->save();
        }

        return $redirected;
    }

    /**
     * @param  Collection<int,CommunicationConversation>  $conversations
     */
    private function moveCorrelatedExistingMessage(
        CommunicationInbox $inbox,
        ?CommunicationMessage $existingMessage,
        CommunicationConversation $survivor,
        Collection $conversations,
    ): void {
        if ($existingMessage === null
            || (int) $existingMessage->conversation_id === (int) $survivor->id) {
            return;
        }
        if (! $conversations->contains(
            static fn (CommunicationConversation $conversation): bool => (
                (int) $conversation->id === (int) $existingMessage->conversation_id
            ),
        )) {
            return;
        }

        CommunicationMessage::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('inbox_id', $inbox->id)
            ->whereKey($existingMessage->id)
            ->update(['conversation_id' => $survivor->id]);
        $source = $conversations->firstWhere('id', (int) $existingMessage->conversation_id);
        if ($source instanceof CommunicationConversation) {
            $this->readState->movePendingMessage($source, $survivor, $existingMessage);
        }
        DB::table('communication_events')
            ->where('tenant_id', $inbox->tenant_id)
            ->where('message_id', $existingMessage->id)
            ->update(['conversation_id' => $survivor->id]);
    }

    /**
     * @param  Collection<int,CommunicationConversation>  $donors
     */
    private function mergeActiveConversations(
        CommunicationInbox $inbox,
        CommunicationConversation $survivor,
        Collection $donors,
        DateTimeInterface $occurredAt,
    ): bool {
        $merged = false;
        $donorList = [];
        foreach ($donors as $donor) {
            $merged = true;
            $donorList[] = $donor;
            $this->copyConversationClients($inbox, $donor, $survivor);
            $this->copyConversationLabels($inbox, $donor, $survivor);

            CommunicationMessage::query()->withoutGlobalScopes()
                ->where('tenant_id', $inbox->tenant_id)
                ->where('conversation_id', $donor->id)
                ->update(['conversation_id' => $survivor->id]);
            DB::table('communication_events')
                ->where('tenant_id', $inbox->tenant_id)
                ->where('conversation_id', $donor->id)
                ->update(['conversation_id' => $survivor->id]);
            DB::table('client_communication_dispatches')
                ->where('tenant_id', $inbox->tenant_id)
                ->where('conversation_id', $donor->id)
                ->update(['conversation_id' => $survivor->id]);
            DB::table('communication_flow_consumptions')
                ->where('tenant_id', $inbox->tenant_id)
                ->where('conversation_id', $donor->id)
                ->update(['conversation_id' => $survivor->id]);

            $survivorIdentity = CommunicationIdentity::query()->withoutGlobalScopes()->find($survivor->identity_id);
            $donorIdentity = CommunicationIdentity::query()->withoutGlobalScopes()->find($donor->identity_id);
            if ($survivorIdentity !== null && $donorIdentity !== null) {
                $this->identityProfiles->mergeFromDonor($survivorIdentity, $donorIdentity);
            }

            $survivor->forceFill([
                'priority' => max((int) $survivor->priority, (int) $donor->priority),
                'assignee_membership_id' => $survivor->assignee_membership_id
                    ?? $donor->assignee_membership_id,
                'work_department_id' => $survivor->work_department_id
                    ?? $donor->work_department_id,
                'last_message_at' => $this->latestDate(
                    $survivor->last_message_at,
                    $donor->last_message_at,
                ),
            ]);
        }

        if ($merged) {
            $this->readState->mergeFragments($survivor, $donorList);
        }

        foreach ($donorList as $donor) {
            $donor->forceFill([
                'status' => ConversationStatus::Resolved,
                'resolved_at' => $occurredAt,
                'assignee_membership_id' => null,
                'snoozed_until' => null,
                'merged_into_conversation_id' => $survivor->id,
                'lock_version' => (int) $donor->lock_version + 1,
            ])->save();
        }

        return $merged;
    }

    /**
     * @param  list<int>  $identityIds
     */
    private function attachIdentityClients(
        CommunicationInbox $inbox,
        CommunicationConversation $conversation,
        array $identityIds,
    ): void {
        $clientIds = DB::table('communication_identity_links')
            ->where('tenant_id', $inbox->tenant_id)
            ->whereIn('identity_id', $identityIds)
            ->pluck('client_id');
        foreach ($clientIds as $clientId) {
            DB::table('communication_conversation_clients')->insertOrIgnore([
                'tenant_id' => $inbox->tenant_id,
                'conversation_id' => $conversation->id,
                'client_id' => $clientId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function copyConversationClients(
        CommunicationInbox $inbox,
        CommunicationConversation $from,
        CommunicationConversation $to,
    ): void {
        $clientIds = DB::table('communication_conversation_clients')
            ->where('tenant_id', $inbox->tenant_id)
            ->where('conversation_id', $from->id)
            ->pluck('client_id');
        foreach ($clientIds as $clientId) {
            DB::table('communication_conversation_clients')->insertOrIgnore([
                'tenant_id' => $inbox->tenant_id,
                'conversation_id' => $to->id,
                'client_id' => $clientId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function copyConversationLabels(
        CommunicationInbox $inbox,
        CommunicationConversation $from,
        CommunicationConversation $to,
    ): void {
        $labels = DB::table('communication_conversation_labels')
            ->where('tenant_id', $inbox->tenant_id)
            ->where('conversation_id', $from->id)
            ->get(['label_id', 'assigned_by_membership_id']);
        foreach ($labels as $label) {
            DB::table('communication_conversation_labels')->insertOrIgnore([
                'tenant_id' => $inbox->tenant_id,
                'conversation_id' => $to->id,
                'label_id' => $label->label_id,
                'assigned_by_membership_id' => $label->assigned_by_membership_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function latestDate(?DateTimeInterface $left, ?DateTimeInterface $right): ?DateTimeInterface
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return $left >= $right ? $left : $right;
    }

    private function signedInt32(int $value): int
    {
        return $value > 0x7FFFFFFF ? $value - 0x100000000 : $value;
    }

    private function maskAddress(string $address): string
    {
        return str_starts_with($address, 'lid:')
            ? 'lid:***'.substr($address, -4)
            : '***'.substr($address, -4);
    }
}
