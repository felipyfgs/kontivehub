<?php

namespace App\Services\Communication\Contact;

use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\GatewayQueryType;
use App\Enums\CommunicationChannel;
use App\Exceptions\CommunicationUnavailableException;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Services\Communication\CommunicationAvailability;
use App\Services\Communication\ProfilePicture\CommunicationProfilePictureRefreshScheduler;
use Carbon\CarbonImmutable;

/** System-context reconciler. It queries only identities already materialised by Laravel. */
final readonly class CommunicationInboxIdentityProfileReconciler
{
    public function __construct(
        private CommunicationTransport $transport,
        private CommunicationInboxIdentityProfileMerger $profiles,
        private CommunicationProfilePictureRefreshScheduler $profilePictures,
        private CommunicationAvailability $availability,
    ) {}

    /** @return array{applied:int,next_identity_id:?int} */
    public function reconcile(
        CommunicationInbox $inbox,
        int $afterIdentityId = 0,
        ?string $observedAt = null,
        ?string $reconciliationId = null,
    ): array {
        try {
            $this->availability->assertEnabled($inbox, true);
        } catch (CommunicationUnavailableException) {
            return ['applied' => 0, 'next_identity_id' => null];
        }

        $stableObservedAt = CarbonImmutable::parse($observedAt ?? now())->utc();
        $stableReconciliationId = $reconciliationId
            ?? 'reconcile-'.substr(hash('sha256', $inbox->id.'|'.$stableObservedAt->format('Y-m-d\\TH:i:s.u\\Z')), 0, 48);
        $identities = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('communication_identities.tenant_id', $inbox->tenant_id)
            ->where('communication_identities.channel', CommunicationChannel::Whatsapp->value)
            ->where('communication_identities.is_active', true)->whereNull('communication_identities.purged_at')
            ->where('communication_identities.id', '>', $afterIdentityId)
            ->whereExists(function ($query) use ($inbox): void {
                $query->selectRaw('1')->from('communication_conversations')
                    ->whereColumn('communication_conversations.identity_id', 'communication_identities.id')
                    ->where('communication_conversations.tenant_id', $inbox->tenant_id)
                    ->where('communication_conversations.inbox_id', $inbox->id)
                    ->whereNull('communication_conversations.merged_into_conversation_id');
            })->orderBy('communication_identities.id')->limit(100)->get();
        if ($identities->isEmpty()) {
            return ['applied' => 0, 'next_identity_id' => null];
        }
        $addresses = $identities->map(fn (CommunicationIdentity $identity): string => (string) $identity->address_encrypted)
            ->filter()->values()->all();
        if ($addresses === []) {
            return ['applied' => 0, 'next_identity_id' => null];
        }
        $result = $this->transport->query(new GatewayQueryData(
            queryId: 'contact-profile-'.substr(hash('sha256', $stableReconciliationId.'|'.$afterIdentityId), 0, 48),
            sessionId: $inbox->session_id,
            type: GatewayQueryType::ContactProfiles, payload: ['users' => $addresses],
        ));
        $profiles = is_array($result['profiles'] ?? null) ? $result['profiles'] : [];
        $byAddress = [];
        foreach ($profiles as $profile) {
            if (is_array($profile) && is_string($profile['user'] ?? null)) {
                $byAddress[$profile['user']] = $profile;
            }
        }
        $applied = 0;
        foreach ($identities as $identity) {
            $payload = $byAddress[(string) $identity->address_encrypted] ?? null;
            if (! is_array($payload) || ($payload['found'] ?? true) !== true) {
                continue; // unknown and failures never clear local observations
            }
            $fields = array_intersect_key($payload, array_flip([
                'address_book_first_name', 'address_book_full_name', 'business_name', 'push_name',
            ]));
            $cleared = is_array($payload['cleared_fields'] ?? null) ? $payload['cleared_fields'] : [];
            $this->profiles->merge(
                $inbox,
                $identity,
                $fields,
                $stableObservedAt,
                $stableReconciliationId.':'.$identity->id,
                $cleared,
            );
            $this->profilePictures->schedule($inbox, $identity);
            $applied++;
        }
        $last = (int) $identities->last()->id;

        return ['applied' => $applied, 'next_identity_id' => $identities->count() === 100 ? $last : null];
    }
}
