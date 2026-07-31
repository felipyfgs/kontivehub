<?php

namespace App\Services\Communication\Contact;

use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;

/** Resolves presentation names only; it never writes back to curated contacts. */
final class ConversationDisplayNameResolver
{
    /**
     * @return array{display_name:string,display_name_source:string,secondary_context:?string}
     */
    public function resolve(CommunicationConversation $conversation): array
    {
        $conversation->loadMissing([
            'identity.contact',
            'identity.clientLinks.clientContact',
            'identity.inboxProfiles',
            'identity.canonicalIdentity.contact',
            'identity.canonicalIdentity.clientLinks.clientContact',
            'identity.canonicalIdentity.inboxProfiles',
            'clients',
        ]);
        $identity = $conversation->identity;
        $canonical = $identity?->canonical_identity_id !== null
            ? $identity->canonicalIdentity
            : $identity;
        $canonicalIdentityId = (int) ($canonical?->id ?? 0);
        $contact = $canonical?->contact;

        $manual = $contact instanceof CommunicationContact && ! $contact->is_provisional
            ? $this->value($contact->name) : null;
        $linkedContactNames = ($canonical?->clientLinks ?? collect())
            ->map(fn ($link): ?string => $link->clientContact?->is_active
                ? $this->value($link->clientContact->name)
                : null)
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values()
            ->all();
        $profile = ($canonical?->inboxProfiles ?? collect())
            ->first(fn ($candidate): bool => (
                (int) $candidate->tenant_id === (int) $conversation->tenant_id
                && (int) $candidate->inbox_id === (int) $conversation->inbox_id
            ));

        $candidates = [
            ['value' => $manual, 'source' => 'MANUAL_CONTACT'],
            ['value' => count($linkedContactNames) === 1 ? $linkedContactNames[0] : null, 'source' => 'CLIENT_CONTACT'],
            ['value' => $this->value($profile?->address_book_full_name), 'source' => 'WHATSAPP_ADDRESS_BOOK'],
            ['value' => $this->value($profile?->address_book_first_name), 'source' => 'WHATSAPP_ADDRESS_BOOK'],
            ['value' => $this->value($profile?->verified_name), 'source' => 'WHATSAPP_USER_INFO'],
            ['value' => $this->value($profile?->business_name), 'source' => 'WHATSAPP_BUSINESS'],
            ['value' => $this->value($profile?->push_name), 'source' => 'WHATSAPP_PUSH_NAME'],
            ['value' => $contact?->is_provisional ? $this->value($contact->name) : null, 'source' => 'LEGACY_PROVISIONAL'],
            ['value' => $this->value($identity?->address_masked), 'source' => 'MASKED_ADDRESS'],
            ['value' => $canonicalIdentityId > 0 ? 'Contato #'.$canonicalIdentityId : 'Contato interno', 'source' => 'OPAQUE_ID'],
        ];
        foreach ($candidates as $candidate) {
            if ($candidate['value'] !== null) {
                return [
                    'display_name' => $candidate['value'],
                    'display_name_source' => $candidate['source'],
                    'secondary_context' => $this->fiscalContext($conversation, $candidate['value']),
                ];
            }
        }

        throw new \LogicException('Fallback de perfil de comunicação indisponível.');
    }

    private function fiscalContext(CommunicationConversation $conversation, string $title): ?string
    {
        $names = $conversation->clients
            ->filter(fn ($client): bool => (bool) $client->is_active)
            ->map(fn ($client): ?string => $this->value($client->display_name ?: $client->legal_name))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values();
        $names = $names->reject(fn (string $name): bool => mb_strtolower($name) === mb_strtolower($title))->values();

        if ($names->isEmpty()) {
            return null;
        }

        return $names->count() === 1
            ? $names->first()
            : $names->first().' +'.($names->count() - 1);
    }

    private function value(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
