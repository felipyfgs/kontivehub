<?php

namespace App\Actions\Communication;

use App\DTO\Communication\ContactCreationData;
use App\Enums\CommunicationChannel;
use App\Exceptions\CommunicationContactApiException;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationMessage;
use App\Services\Communication\Contact\SharedVCardParser;
use App\Services\Communication\ContactCanonicalizer;
use App\Services\Communication\ContactService;
use App\Services\Communication\ConversationCanonicalizer;
use App\Services\Communication\MessageAvailability;
use App\Services\Communication\WhatsAppAddressNormalizer;
use Illuminate\Validation\ValidationException;

final readonly class SaveSharedContactAction
{
    public function __construct(
        private SharedVCardParser $parser,
        private WhatsAppAddressNormalizer $normalizer,
        private ContactService $contacts,
        private ContactCanonicalizer $contactCanonicalizer,
        private ConversationCanonicalizer $conversationCanonicalizer,
        private MessageAvailability $availability,
    ) {}

    /** @return array{outcome:'created'|'existing',contact:CommunicationContact} */
    public function handle(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
        int $contactIndex,
        int $phoneIndex,
    ): array {
        $conversation = $this->conversationCanonicalizer->conversation($conversation);
        $message = CommunicationMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('inbox_id', $conversation->inbox_id)
            ->where('conversation_id', $conversation->id)
            ->whereKey($message->id)
            ->firstOrFail();
        if ($this->availability->forMessage($message)->state->value !== 'AVAILABLE') {
            throw ValidationException::withMessages(['message' => ['Mensagem indisponível para importar contato.']]);
        }

        $content = is_array($message->content_encrypted) ? $message->content_encrypted : [];
        $sharedContacts = is_array($content['contacts'] ?? null) ? array_values($content['contacts']) : [];
        $shared = $sharedContacts[$contactIndex] ?? null;
        if (! is_array($shared)) {
            throw ValidationException::withMessages(['contact_index' => ['Contato compartilhado inválido.']]);
        }
        $parsed = $this->parser->parse(
            (string) ($shared['vcard'] ?? ''),
            is_string($shared['display_name'] ?? null) ? $shared['display_name'] : null,
        );
        $selected = $parsed['phones'][$phoneIndex] ?? null;
        if (! is_array($selected)) {
            throw ValidationException::withMessages(['phone_index' => ['Telefone compartilhado inválido.']]);
        }
        $phone = $this->normalizer->normalize((string) $selected['phone']);
        $existing = $this->findExisting((int) $conversation->tenant_id, $phone);
        if ($existing !== null) {
            return ['outcome' => 'existing', 'contact' => $existing];
        }

        try {
            $created = $this->contacts->create(new ContactCreationData(
                name: $parsed['display_name'] !== '' ? $parsed['display_name'] : null,
                phone: $phone,
                clientId: null,
                clientContactId: null,
                isPrimary: false,
                receivesAutomatic: true,
            ));

            return ['outcome' => 'created', 'contact' => $created];
        } catch (CommunicationContactApiException) {
            $existing = $this->findExisting((int) $conversation->tenant_id, $phone);
            if ($existing === null) {
                throw ValidationException::withMessages(['phone_index' => ['Telefone não pôde ser importado.']]);
            }

            return ['outcome' => 'existing', 'contact' => $existing];
        }
    }

    private function findExisting(int $tenantId, string $phone): ?CommunicationContact
    {
        $identity = CommunicationIdentity::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('channel', CommunicationChannel::WhatsApp)
            ->where('address_hash', hash('sha256', $phone))
            ->whereNull('purged_at')
            ->first();
        if ($identity === null) {
            return null;
        }
        $identity = $this->conversationCanonicalizer->identity($identity);
        $contact = CommunicationContact::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->find($identity->contact_id);
        if ($contact === null || $contact->purged_at !== null) {
            return null;
        }

        return $this->contactCanonicalizer->contact($contact)->load([
            'identities.clientLinks.client',
            'identities.clientLinks.clientContact',
        ]);
    }
}
