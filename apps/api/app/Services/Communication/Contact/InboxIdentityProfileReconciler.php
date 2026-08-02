<?php

namespace App\Services\Communication\Contact;

use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\GatewayQueryType;
use App\Enums\CommunicationChannel;
use App\Exceptions\CommunicationUnavailableException;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Services\Communication\Availability;
use App\Services\Communication\ConversationCanonicalizer;
use App\Services\Communication\ProfilePicture\ProfilePictureRefreshScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/** System-context reconciler. It queries only identities already materialised by Laravel. */
final readonly class InboxIdentityProfileReconciler
{
    private const BATCH_SIZE = 100;

    /** @var list<string> */
    private const PROFILE_FIELDS = [
        'address_book_first_name',
        'address_book_full_name',
        'verified_name',
        'business_name',
        'push_name',
    ];

    public function __construct(
        private CommunicationTransport $transport,
        private InboxIdentityProfileMerger $profiles,
        private ProfilePictureRefreshScheduler $profilePictures,
        private Availability $availability,
        private ConversationCanonicalizer $canonicalizer,
    ) {}

    /** @return array{applied:int,next_identity_id:?int} */
    public function reconcile(
        CommunicationInbox $inbox,
        int $afterIdentityId = 0,
        ?string $observedAt = null,
        ?string $reconciliationId = null,
    ): array {
        if (! $this->isAvailable($inbox)) {
            return ['applied' => 0, 'next_identity_id' => null];
        }

        $canonicalIdentities = $this->knownCanonicalIdentities($inbox, $afterIdentityId);
        if ($canonicalIdentities->isEmpty()) {
            return ['applied' => 0, 'next_identity_id' => null];
        }

        [$stableObservedAt, $stableReconciliationId] = $this->stableObservation(
            $inbox,
            $observedAt,
            $reconciliationId,
        );
        $applied = $this->reconcileCanonicalIdentities(
            $inbox,
            $canonicalIdentities,
            $stableObservedAt,
            $stableReconciliationId,
        );
        $lastIdentityId = (int) $canonicalIdentities->last()->id;

        return [
            'applied' => $applied,
            'next_identity_id' => $canonicalIdentities->count() === self::BATCH_SIZE
                ? $lastIdentityId
                : null,
        ];
    }

    public function reconcileIdentity(
        CommunicationInbox $inbox,
        CommunicationIdentity $identity,
        ?string $observedAt = null,
        ?string $reconciliationId = null,
    ): int {
        if ((int) $identity->tenant_id !== (int) $inbox->tenant_id
            || $identity->channel !== CommunicationChannel::WhatsApp
            || ! $identity->is_active
            || $identity->purged_at !== null
            || ! $this->isAvailable($inbox)) {
            return 0;
        }

        $canonicalIdentity = $this->canonicalizer->identity($identity);
        if (! $this->isKnownInInbox($inbox, (int) $canonicalIdentity->id)) {
            return 0;
        }

        [$stableObservedAt, $stableReconciliationId] = $this->stableObservation(
            $inbox,
            $observedAt,
            $reconciliationId,
        );

        return $this->reconcileCanonicalIdentities(
            $inbox,
            new Collection([$canonicalIdentity]),
            $stableObservedAt,
            $stableReconciliationId,
        );
    }

    private function isAvailable(CommunicationInbox $inbox): bool
    {
        try {
            $this->availability->assertEnabled($inbox, true);
        } catch (CommunicationUnavailableException) {
            return false;
        }

        return true;
    }

    /** @return Collection<int, CommunicationIdentity> */
    private function knownCanonicalIdentities(
        CommunicationInbox $inbox,
        int $afterIdentityId,
    ): Collection {
        return CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('communication_identities.tenant_id', $inbox->tenant_id)
            ->where('communication_identities.channel', CommunicationChannel::WhatsApp->value)
            ->where('communication_identities.is_active', true)
            ->whereNull('communication_identities.purged_at')
            ->whereNull('communication_identities.canonical_identity_id')
            ->where('communication_identities.id', '>', $afterIdentityId)
            ->where(function ($known) use ($inbox): void {
                $known->whereExists(function ($profiles) use ($inbox): void {
                    $profiles->selectRaw('1')
                        ->from('communication_inbox_identity_profiles')
                        ->whereColumn(
                            'communication_inbox_identity_profiles.identity_id',
                            'communication_identities.id',
                        )
                        ->where('communication_inbox_identity_profiles.tenant_id', $inbox->tenant_id)
                        ->where('communication_inbox_identity_profiles.inbox_id', $inbox->id);
                })->orWhereExists(function ($conversations) use ($inbox): void {
                    $conversations->selectRaw('1')
                        ->from('communication_conversations as known_conversations')
                        ->join('communication_identities as known_identities', function ($join): void {
                            $join->on('known_identities.id', '=', 'known_conversations.identity_id')
                                ->on('known_identities.tenant_id', '=', 'known_conversations.tenant_id');
                        })
                        ->where('known_conversations.tenant_id', $inbox->tenant_id)
                        ->where('known_conversations.inbox_id', $inbox->id)
                        ->whereNull('known_conversations.purged_at')
                        ->whereNull('known_conversations.merged_into_conversation_id')
                        ->whereRaw(
                            'COALESCE(known_identities.canonical_identity_id, known_identities.id) = communication_identities.id',
                        );
                });
            })
            ->orderBy('communication_identities.id')
            ->limit(self::BATCH_SIZE)
            ->get();
    }

    private function isKnownInInbox(CommunicationInbox $inbox, int $canonicalIdentityId): bool
    {
        $hasProfile = $inbox->newQuery()->withoutGlobalScopes()
            ->from('communication_inbox_identity_profiles')
            ->where('tenant_id', $inbox->tenant_id)
            ->where('inbox_id', $inbox->id)
            ->where('identity_id', $canonicalIdentityId)
            ->exists();
        if ($hasProfile) {
            return true;
        }

        return $inbox->newQuery()->withoutGlobalScopes()
            ->from('communication_conversations as known_conversations')
            ->join('communication_identities as known_identities', function ($join): void {
                $join->on('known_identities.id', '=', 'known_conversations.identity_id')
                    ->on('known_identities.tenant_id', '=', 'known_conversations.tenant_id');
            })
            ->where('known_conversations.tenant_id', $inbox->tenant_id)
            ->where('known_conversations.inbox_id', $inbox->id)
            ->whereNull('known_conversations.purged_at')
            ->whereNull('known_conversations.merged_into_conversation_id')
            ->whereRaw(
                'COALESCE(known_identities.canonical_identity_id, known_identities.id) = ?',
                [$canonicalIdentityId],
            )
            ->exists();
    }

    /**
     * @param  Collection<int, CommunicationIdentity>  $canonicalIdentities
     */
    private function reconcileCanonicalIdentities(
        CommunicationInbox $inbox,
        Collection $canonicalIdentities,
        CarbonImmutable $observedAt,
        string $reconciliationId,
    ): int {
        $canonicalIds = $canonicalIdentities->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $members = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('channel', CommunicationChannel::WhatsApp->value)
            ->where('is_active', true)
            ->whereNull('purged_at')
            ->where(static fn ($query) => $query
                ->whereIn('id', $canonicalIds)
                ->orWhereIn('canonical_identity_id', $canonicalIds))
            ->orderByRaw('CASE WHEN canonical_identity_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->get();
        $membersByCanonicalId = $members->groupBy(
            static fn (CommunicationIdentity $member): int => (int) ($member->canonical_identity_id ?: $member->id),
        );
        $addresses = $members->map(
            static fn (CommunicationIdentity $member): string => trim((string) $member->address_encrypted),
        )->filter()->unique()->values()->all();
        $profilesByAddress = $this->queryProfiles($inbox, $addresses, $reconciliationId);
        $applied = 0;

        foreach ($canonicalIdentities as $canonicalIdentity) {
            $fields = [];
            $found = false;
            foreach ($membersByCanonicalId->get((int) $canonicalIdentity->id, collect()) as $member) {
                $address = trim((string) $member->address_encrypted);
                $payload = $address !== '' ? ($profilesByAddress[$address] ?? null) : null;
                if (! is_array($payload) || ($payload['found'] ?? null) !== true) {
                    continue;
                }
                $found = true;
                foreach (self::PROFILE_FIELDS as $field) {
                    if (array_key_exists($field, $fields)) {
                        continue;
                    }
                    $value = $payload[$field] ?? null;
                    if (is_string($value) && trim($value) !== '') {
                        $fields[$field] = $value;
                    }
                }
            }
            if (! $found) {
                continue;
            }

            // CONTACT_PROFILES is a local snapshot: omissions and found=false never clear observations.
            $this->profiles->merge(
                $inbox,
                $canonicalIdentity,
                $fields,
                $observedAt,
                $reconciliationId.':'.$canonicalIdentity->id,
            );
            $this->profilePictures->schedule($inbox, $canonicalIdentity);
            $applied++;
        }

        return $applied;
    }

    /**
     * @param  list<string>  $addresses
     * @return array<string, array<string, mixed>>
     */
    private function queryProfiles(
        CommunicationInbox $inbox,
        array $addresses,
        string $reconciliationId,
    ): array {
        $profilesByAddress = [];
        foreach (array_chunk($addresses, self::BATCH_SIZE) as $chunkIndex => $chunk) {
            $result = $this->transport->query(new GatewayQueryData(
                queryId: 'contact-profile-'.substr(hash(
                    'sha256',
                    $reconciliationId.'|'.$chunkIndex.'|'.implode('|', $chunk),
                ), 0, 48),
                sessionId: $inbox->session_id,
                type: GatewayQueryType::ContactProfiles,
                payload: ['users' => $chunk],
            ));
            foreach (is_array($result['profiles'] ?? null) ? $result['profiles'] : [] as $profile) {
                $address = is_array($profile) && is_string($profile['user'] ?? null)
                    ? trim($profile['user'])
                    : '';
                if ($address !== '' && in_array($address, $chunk, true) && ! isset($profilesByAddress[$address])) {
                    $profilesByAddress[$address] = $profile;
                }
            }
        }

        return $profilesByAddress;
    }

    /** @return array{0:CarbonImmutable,1:string} */
    private function stableObservation(
        CommunicationInbox $inbox,
        ?string $observedAt,
        ?string $reconciliationId,
    ): array {
        $stableObservedAt = CarbonImmutable::parse($observedAt ?? now())->utc();
        $stableReconciliationId = $reconciliationId
            ?? 'reconcile-'.substr(hash(
                'sha256',
                $inbox->id.'|'.$stableObservedAt->format('Y-m-d\\TH:i:s.u\\Z'),
            ), 0, 48);

        return [$stableObservedAt, $stableReconciliationId];
    }
}
