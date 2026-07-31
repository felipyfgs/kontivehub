<?php

namespace App\Services\Communication\Conversation;

use App\Exceptions\CommunicationConversationApiException;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Services\Communication\Availability;
use App\Services\Communication\ConversationCanonicalizer;

/** Explicit rollout gate for creating a new peer thread; defaults are deliberately closed. */
final class OutboundConversationGate
{
    public function __construct(
        private readonly Availability $availability,
        private readonly ConversationCanonicalizer $canonicalizer,
    ) {}

    public function assertAllowed(CommunicationInbox $inbox, ?CommunicationIdentity $identity = null): void
    {
        $tenantId = (int) $inbox->tenant_id;
        $allowlisted = array_map('intval', (array) config('communication.outbound_conversation.allowed_tenant_ids', []));
        if (! (bool) config('communication.outbound_conversation.enabled', false)
            || (bool) config('communication.outbound_conversation.kill_switch', true)
            || (! (bool) config('communication.outbound_conversation.allow_all_tenants', false) && ! in_array($tenantId, $allowlisted, true))) {
            throw CommunicationConversationApiException::outboundInitiationDisabled();
        }

        $this->availability->assertEnabled($inbox, true);
        if ($identity !== null) {
            if ((int) $identity->tenant_id !== $tenantId
                || $identity->purged_at !== null
                || ! $identity->is_active) {
                throw CommunicationConversationApiException::outboundInitiationDisabled();
            }
            $identityIds = $this->canonicalizer->identityIds($identity);
            $inboxHash = trim((string) $inbox->address_hash);
            $selfChat = $inboxHash !== '' && CommunicationIdentity::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $identityIds)
                ->pluck('address_hash')
                ->contains(static fn ($hash): bool => is_string($hash)
                    && $hash !== ''
                    && hash_equals($inboxHash, $hash));
            if ($selfChat) {
                throw CommunicationConversationApiException::selfChat();
            }
        }
    }
}
