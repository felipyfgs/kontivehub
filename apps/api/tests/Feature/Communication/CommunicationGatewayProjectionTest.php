<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\GatewayEventType;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\CommunicationChannel;
use App\Events\CommunicationEventCommitted;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationEvent;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxIdentityProfile;
use App\Models\CommunicationMessage;
use App\Models\Tenant;
use App\Services\Communication\Pairing\PairingStateStore;
use App\Services\Communication\Security\HmacSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class CommunicationGatewayProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
            'communication.hmac.current_key_id' => 'test-key',
            'communication.hmac.current_secret' => str_repeat('h', 32),
        ]);
    }

    public function test_message_actions_update_the_original_message_without_parallel_conversation(): void
    {
        [$inbox, $conversation, $message] = $this->context();

        $this->postEvent($inbox, GatewayEventType::MessageActionReceived, 'gateway-edit-action-0001', [
            'action' => 'EDIT',
            'provider_message_id' => 'provider-edit-action-0001',
            'target_message_id' => $message->provider_message_id,
            'from' => '+5511999990001',
            'kind' => 'TEXT',
            'provider_type' => 'conversation',
            'family' => 'TEXT',
            'text' => 'Conteúdo editado',
        ])->assertNoContent();
        $this->postEvent($inbox, GatewayEventType::MessageActionReceived, 'gateway-reaction-action-0001', [
            'action' => 'REACTION',
            'provider_message_id' => 'provider-reaction-action-0001',
            'target_message_id' => $message->provider_message_id,
            'from' => '+5511999990001',
            'emoji' => '👍',
        ])->assertNoContent();
        $this->postEvent($inbox, GatewayEventType::MessageActionReceived, 'gateway-poll-vote-action-0001', [
            'action' => 'POLL_VOTE',
            'provider_message_id' => 'provider-poll-vote-action-0001',
            'target_message_id' => $message->provider_message_id,
            'from' => '+5511999990001',
            'option_hashes' => [str_repeat('a', 64)],
        ])->assertNoContent();

        $message->refresh();
        $this->assertSame('Conteúdo editado', $message->body_encrypted);
        $this->assertNotEmpty($message->metadata['edited_at'] ?? null);
        $this->assertSame(['👍'], array_values($message->content_encrypted['reactions'] ?? []));
        $this->assertSame(
            [[
                'option_names' => [],
                'option_hashes' => [str_repeat('a', 64)],
            ]],
            array_values($message->content_encrypted['poll_votes'] ?? []),
        );
        $this->assertDatabaseCount('communication_conversations', 1);
        $this->assertDatabaseCount('communication_messages', 1);
        $this->assertSame($conversation->id, $message->conversation_id);

        $this->postEvent($inbox, GatewayEventType::MessageReceived, 'gateway-original-after-edit-0001', [
            'provider_message_id' => $message->provider_message_id,
            'from' => '+5511999990001',
            'kind' => 'TEXT',
            'provider_type' => 'conversation',
            'family' => 'TEXT',
            'text' => 'Conteúdo original',
        ])->assertNoContent();
        $message->refresh();
        $this->assertSame('Conteúdo editado', $message->body_encrypted);
        $this->assertSame('Conteúdo editado', $message->content_encrypted['text'] ?? null);
        $this->assertSame(['👍'], array_values($message->content_encrypted['reactions'] ?? []));
        $this->assertDatabaseCount('communication_messages', 1);

        $this->postEvent($inbox, GatewayEventType::MessageActionReceived, 'gateway-revoke-action-0001', [
            'action' => 'REVOKE',
            'provider_message_id' => 'provider-revoke-action-0001',
            'target_message_id' => $message->provider_message_id,
            'from' => '+5511999990001',
        ])->assertNoContent();
        $this->assertSame('Conteúdo editado', $message->refresh()->body_encrypted);
        $this->assertNotNull($message->revoked_at);
        $this->assertTrue($message->metadata['revoked'] ?? false);
    }

    public function test_message_actions_apply_alias_evidence_and_accept_the_canonical_peer_afterward(): void
    {
        [$inbox, $conversation, $message] = $this->context();
        $lid = 'lid:149865032093945';
        $remotePn = '+5511999990001';
        $this->postEvent($inbox, GatewayEventType::MessageActionReceived, 'gateway-action-alias-0001', [
            'action' => 'REACTION',
            'provider_message_id' => 'provider-action-alias-0001',
            'target_message_id' => $message->provider_message_id,
            'from' => $lid,
            'source_identity' => [
                'primary' => $lid,
                'primary_kind' => 'LID',
                'alternate' => $remotePn,
                'alternate_kind' => 'PN',
                'evidence' => 'MESSAGE_SOURCE_ALT',
            ],
            'emoji' => '✅',
        ])->assertNoContent();

        $lidIdentity = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('address_hash', hash('sha256', $lid))
            ->sole();
        $pnIdentity = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('address_hash', hash('sha256', $remotePn))
            ->sole();
        $this->assertSame($pnIdentity->id, $lidIdentity->canonical_identity_id);
        $message->forceFill(['identity_id' => $lidIdentity->id])->save();

        $this->postEvent($inbox, GatewayEventType::MessageActionReceived, 'gateway-action-pn-0002', [
            'action' => 'EDIT',
            'provider_message_id' => 'provider-action-pn-0002',
            'target_message_id' => $message->provider_message_id,
            'from' => $remotePn,
            'source_identity' => [
                'primary' => $remotePn,
                'primary_kind' => 'PN',
                'evidence' => 'CHAT',
            ],
            'kind' => 'TEXT',
            'provider_type' => 'conversation',
            'family' => 'TEXT',
            'text' => 'Editada pela PN canônica',
        ])->assertNoContent();

        $this->assertSame('Editada pela PN canônica', $message->refresh()->body_encrypted);
        $this->assertSame(['✅'], array_values($message->content_encrypted['reactions'] ?? []));
        $this->assertDatabaseCount('communication_conversations', 1);
        $this->assertSame($conversation->id, $message->conversation_id);
    }

    public function test_history_batch_is_idempotent_preserves_direction_quote_and_resolved_state(): void
    {
        [$inbox, $conversation, $existing] = $this->context(ConversationStatus::Resolved);
        $occurredAt = Carbon::parse('2026-07-20T10:00:00-03:00');
        $event = [
            'contract_version' => 'v1',
            'gateway_event_id' => 'gateway-history-batch-0001',
            'session_id' => $inbox->session_id,
            'type' => GatewayEventType::HistorySynced->value,
            'occurred_at' => $occurredAt->toIso8601String(),
            'payload' => [
                'batch_id' => 'history-batch-0001',
                'complete' => true,
                'messages' => [
                    [
                        'provider_message_id' => 'provider-history-out-0001',
                        'from' => '+5511999990001',
                        'direction' => 'OUTBOUND',
                        'kind' => 'TEXT',
                        'provider_type' => 'conversation',
                        'family' => 'TEXT',
                        'text' => 'Mensagem anterior enviada',
                        'occurred_at' => '2026-07-18T10:00:00-03:00',
                    ],
                    [
                        'provider_message_id' => 'provider-history-in-0001',
                        'from' => '+5511999990001',
                        'direction' => 'INBOUND',
                        'kind' => 'TEXT',
                        'provider_type' => 'conversation',
                        'family' => 'TEXT',
                        'text' => 'Resposta histórica',
                        'reply_to' => ['provider_message_id' => 'provider-history-out-0001'],
                        'occurred_at' => '2026-07-18T10:01:00-03:00',
                    ],
                ],
            ],
        ];

        $this->postSigned($event)->assertNoContent()->assertHeader('X-Communication-Result', 'processed');
        $this->postSigned($event)->assertNoContent()->assertHeader('X-Communication-Result', 'duplicate');

        $outbound = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('provider_message_id', 'provider-history-out-0001')->firstOrFail();
        $inbound = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('provider_message_id', 'provider-history-in-0001')->firstOrFail();
        $this->assertSame(MessageDirection::Outbound, $outbound->direction);
        $this->assertSame(MessageDirection::Inbound, $inbound->direction);
        $this->assertSame($outbound->id, $inbound->reply_to_message_id);
        $this->assertTrue($outbound->metadata['history'] ?? false);
        $this->assertSame(ConversationStatus::Resolved, $conversation->refresh()->status);
        $this->assertSame(3, CommunicationMessage::query()->withoutGlobalScopes()->count());
        $this->assertSame($existing->conversation_id, $outbound->conversation_id);
    }

    public function test_contact_profile_event_persists_and_broadcasts_only_sanitized_projection(): void
    {
        [$inbox, $conversation] = $this->context();

        $this->postEvent($inbox, GatewayEventType::ContactProfileChanged, 'gateway-profile-sanitized-0001', [
            'user' => '+5511999990001',
            'source' => 'ADDRESS_BOOK',
            'display_name' => 'Maria Silva',
            'address_book_first_name' => 'Maria',
            'address_book_full_name' => 'Maria Silva',
            'about' => 'Dados privados do contato',
            'picture_id' => 'picture-private-0001',
            'from_full_sync' => true,
            'cleared_fields' => ['push_name'],
            'source_identity' => [
                'primary' => 'lid:149865032093945',
                'primary_kind' => 'LID',
                'alternate' => '+5511999990001',
                'alternate_kind' => 'PN',
                'evidence' => 'MESSAGE_SOURCE_ALT',
            ],
        ])->assertNoContent();

        $event = CommunicationEvent::query()->withoutGlobalScopes()
            ->where('gateway_event_id', 'gateway-profile-sanitized-0001')
            ->sole();
        $expectedPayload = [
            'source' => 'ADDRESS_BOOK',
            'identity_id' => $conversation->identity_id,
            'changed_fields' => ['address_book_first_name', 'address_book_full_name', 'about', 'picture_id'],
            'cleared_fields' => ['push_name'],
            'from_full_sync' => true,
        ];
        $this->assertSame($expectedPayload, $event->payload);
        $this->assertSame($conversation->id, $event->conversation_id);
        $broadcast = CommunicationEventCommitted::fromModel($event)->broadcastWith();
        $this->assertSame($expectedPayload, $broadcast['payload']);
        $this->assertSame($conversation->id, $broadcast['conversation_id']);
    }

    public function test_ephemeral_presence_is_scoped_to_the_conversation_without_creating_message(): void
    {
        [$inbox, $conversation] = $this->context();
        $before = CommunicationMessage::query()->withoutGlobalScopes()->count();

        $this->postEvent($inbox, GatewayEventType::ChatPresenceChanged, 'gateway-presence-chat-0001', [
            'from' => '+5511999990001',
            'presence' => 'COMPOSING',
            'media' => 'TEXT',
            'ttl_seconds' => 15,
        ])->assertNoContent();

        $this->assertSame($before, CommunicationMessage::query()->withoutGlobalScopes()->count());
        $this->assertDatabaseHas('communication_events', [
            'gateway_event_id' => 'gateway-presence-chat-0001',
            'conversation_id' => $conversation->id,
            'message_id' => null,
            'type' => GatewayEventType::ChatPresenceChanged->value,
        ]);
    }

    public function test_presence_and_profile_follow_canonical_peer_without_regressing_last_seen(): void
    {
        [$inbox, $conversation] = $this->context();
        $rootIdentity = $conversation->identity()->withoutGlobalScopes()->firstOrFail();
        $contact = $rootIdentity->contact()->withoutGlobalScopes()->firstOrFail();
        $lid = 'lid:149865032093945';
        $lidIdentity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $inbox->tenant_id,
            'contact_id' => $contact->id,
            'canonical_identity_id' => $rootIdentity->id,
            'channel' => CommunicationChannel::WhatsApp,
            'address_encrypted' => $lid,
            'address_hash' => hash('sha256', $lid),
            'address_masked' => 'lid:***3945',
            'is_active' => true,
        ]);
        $donorConversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $inbox->tenant_id,
            'inbox_id' => $inbox->id,
            'identity_id' => $lidIdentity->id,
            'status' => ConversationStatus::Resolved,
            'resolved_at' => now(),
            'merged_into_conversation_id' => $conversation->id,
            'last_message_at' => now()->subMinute(),
        ]);
        $donorContact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $inbox->tenant_id,
            'merged_into_contact_id' => $contact->id,
            'is_provisional' => true,
            'is_active' => false,
        ]);
        $rootIdentity->forceFill(['last_seen_at' => '2026-07-28T16:00:00+00:00'])->save();

        $this->postEvent(
            $inbox,
            GatewayEventType::ContactPresenceChanged,
            'gateway-presence-canonical-0001',
            [
                'from' => $lid,
                'presence' => 'AVAILABLE',
                'available' => true,
                'last_seen' => '2026-07-28T15:00:00+00:00',
            ],
        )->assertNoContent();
        $this->postEvent(
            $inbox,
            GatewayEventType::ContactProfileChanged,
            'gateway-profile-canonical-0001',
            [
                'user' => $lid,
                'display_name' => 'Nome pelo perfil',
                'picture_id' => 'profile-picture-0001',
            ],
        )->assertNoContent();

        $this->assertSame(
            '2026-07-28T16:00:00+00:00',
            $rootIdentity->refresh()->last_seen_at?->toIso8601String(),
        );
        $this->assertNull(data_get($contact->refresh()->metadata, 'whatsapp_profile'));
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()
            ->where('inbox_id', $inbox->id)
            ->where('identity_id', $rootIdentity->id)
            ->firstOrFail();
        $this->assertSame('Nome pelo perfil', $profile->address_book_full_name);
        $this->assertSame('profile-picture-0001', $profile->picture_id);
        $this->assertNull($donorContact->refresh()->metadata);
        foreach (['gateway-presence-canonical-0001', 'gateway-profile-canonical-0001'] as $eventId) {
            $this->assertDatabaseHas('communication_events', [
                'gateway_event_id' => $eventId,
                'conversation_id' => $conversation->id,
            ]);
            $this->assertDatabaseMissing('communication_events', [
                'gateway_event_id' => $eventId,
                'conversation_id' => $donorConversation->id,
            ]);
        }
    }

    public function test_common_semantic_families_are_encrypted_without_open_metadata_or_text_fallback(): void
    {
        [$inbox] = $this->context();
        $fixtures = [
            ['TEXT', 'extendedTextMessage', ['text' => 'Veja', 'link_preview' => [
                'url' => 'http://example.com', 'title' => 'Exemplo', 'description' => 'Descrição',
            ]]],
            ['AUDIO', 'audioMessage', [
                'ptt' => true, 'duration_seconds' => 12, 'media_state' => 'UNAVAILABLE',
            ]],
            ['VIDEO', 'videoMessage', [
                'gif' => true, 'duration_seconds' => 3, 'media_state' => 'UNAVAILABLE',
            ]],
            ['LOCATION', 'liveLocationMessage', ['location' => [
                'latitude' => -23.5, 'longitude' => -46.6, 'caption' => 'Ao vivo', 'live' => true,
                'accuracy_meters' => 5, 'sequence' => 2,
            ]]],
            ['CONTACT', 'contactsArrayMessage', ['contacts' => [
                ['display_name' => 'Cliente', 'vcard' => "BEGIN:VCARD\nFN:Cliente\nEND:VCARD"],
            ]]],
            ['POLL', 'pollCreationMessageV3', ['poll' => [
                'name' => 'Escolha', 'options' => ['A', 'B'], 'selectable_options' => 1,
            ]]],
            ['INTERACTIVE', 'buttonsResponseMessage', ['interactive' => [
                'mode' => 'BUTTON_RESPONSE', 'selected_id' => 'yes', 'display_text' => 'Sim',
            ]]],
            ['UNSUPPORTED', 'futureMessage', ['content_present' => true, 'variants' => ['futureMessage']]],
        ];

        foreach ($fixtures as $index => [$kind, $providerType, $content]) {
            $this->postEvent($inbox, GatewayEventType::MessageReceived, 'gateway-rich-family-'.($index + 1000), [
                'provider_message_id' => 'provider-rich-family-'.($index + 1000),
                'from' => '+5511999990001',
                'kind' => $kind,
                'provider_type' => $providerType,
                'family' => $kind,
                ...$content,
            ])->assertNoContent();
        }

        $unsupported = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('provider_type', 'futureMessage')->firstOrFail();
        $this->assertSame(MessageKind::Unsupported, $unsupported->kind);
        $this->assertNull($unsupported->body_encrypted);
        $this->assertTrue($unsupported->content_encrypted['content_present']);
        foreach (CommunicationMessage::query()->withoutGlobalScopes()->whereNotNull('provider_type')->get() as $message) {
            $this->assertArrayNotHasKey('location', $message->metadata ?? []);
            $this->assertArrayNotHasKey('contacts', $message->metadata ?? []);
            $this->assertArrayNotHasKey('poll', $message->metadata ?? []);
            $this->assertArrayNotHasKey('interactive', $message->metadata ?? []);
        }
        $raw = \DB::table('communication_messages')->where('id', $unsupported->id)->value('content_encrypted');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('futureMessage', $raw);
    }

    public function test_canonical_pairing_events_project_session_state(): void
    {
        [$inbox] = $this->context();

        $this->postEvent($inbox, GatewayEventType::PairingUpdated, 'gateway-pairing-qr-0001', [
            'event' => 'QR_AVAILABLE',
        ])->assertNoContent();
        $this->assertSame(InboxStatus::Connecting, $inbox->refresh()->status);

        $this->postEvent($inbox, GatewayEventType::PairingUpdated, 'gateway-pairing-success-0001', [
            'event' => 'PAIRED',
        ])->assertNoContent();
        $this->assertSame(InboxStatus::Connected, $inbox->refresh()->status);
        $this->assertNotNull($inbox->connected_at);
    }

    public function test_same_provider_message_id_remains_isolated_by_tenant_session(): void
    {
        [$firstInbox] = $this->context(sessionId: 'session-projection-tenant-0001');
        [$secondInbox] = $this->context(sessionId: 'session-projection-tenant-0002');
        $payload = [
            'provider_message_id' => 'provider-shared-rich-0001',
            'from' => '+5511999990001',
            'kind' => 'CONTACT',
            'provider_type' => 'contactMessage',
            'family' => 'CONTACT',
            'contacts' => [['display_name' => 'Cliente', 'vcard' => 'BEGIN:VCARD']],
        ];

        $this->postEvent($firstInbox, GatewayEventType::MessageReceived, 'gateway-shared-tenant-0001', $payload)
            ->assertNoContent();
        $this->postEvent($secondInbox, GatewayEventType::MessageReceived, 'gateway-shared-tenant-0002', $payload)
            ->assertNoContent();

        $messages = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('provider_message_id', 'provider-shared-rich-0001')->get();
        $this->assertCount(2, $messages);
        $this->assertEqualsCanonicalizing(
            [(int) $firstInbox->tenant_id, (int) $secondInbox->tenant_id],
            $messages->pluck('tenant_id')->map(fn ($id): int => (int) $id)->all(),
        );
    }

    public function test_session_events_require_the_canonical_public_states(): void
    {
        [$inbox] = $this->context();

        $this->postEvent($inbox, GatewayEventType::SessionStatusChanged, 'gateway-canonical-connecting-0001', [
            'status' => 'CONNECTING',
        ])->assertNoContent();
        $this->assertSame(InboxStatus::Connecting, $inbox->refresh()->status);

        $this->postEvent($inbox, GatewayEventType::SessionStatusChanged, 'gateway-canonical-disconnected-0001', [
            'status' => 'DISCONNECTED',
            'reason_code' => 'CONNECT_RETRIES_EXHAUSTED',
        ])->assertNoContent();
        $this->assertSame(InboxStatus::Disconnected, $inbox->refresh()->status);

        $pairing = app(PairingStateStore::class)->get((int) $inbox->id);
        $this->assertSame('CONNECT_RETRIES_EXHAUSTED', $pairing['error_code'] ?? null);
    }

    public function test_terminal_pairing_error_remains_available_to_the_administrative_poll(): void
    {
        [$inbox] = $this->context();

        $this->postEvent($inbox, GatewayEventType::PairingUpdated, 'gateway-pairing-terminal-0001', [
            'event' => 'error',
            'error_code' => 'SESSION_ALREADY_PAIRED',
        ])->assertNoContent();
        $this->assertSame(InboxStatus::Disconnected, $inbox->refresh()->status);

        $pairing = app(PairingStateStore::class)->get((int) $inbox->id);
        $this->assertSame('error', $pairing['event'] ?? null);
        $this->assertSame('SESSION_ALREADY_PAIRED', $pairing['error_code'] ?? null);
        $this->assertArrayHasKey('expires_at', $pairing);
    }

    /** @return array{CommunicationInbox,CommunicationConversation,CommunicationMessage} */
    private function context(
        ConversationStatus $status = ConversationStatus::Open,
        string $sessionId = 'session-projection-0001',
    ): array {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Atendimento',
            'session_id' => $sessionId,
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
        ]);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente',
            'is_active' => true,
        ]);
        $address = '+5511999990001';
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::WhatsApp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => '***0001',
            'is_active' => true,
        ]);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => $status,
            'resolved_at' => $status === ConversationStatus::Resolved ? now() : null,
            'last_message_at' => now(),
        ]);
        $message = CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $identity->id,
            'direction' => MessageDirection::Inbound,
            'kind' => MessageKind::Text,
            'source' => MessageSource::Gateway,
            'status' => MessageStatus::Delivered,
            'body_encrypted' => 'Conteúdo original',
            'provider_message_id' => 'provider-original-0001',
            'content_digest' => hash('sha256', 'Conteúdo original'),
            'occurred_at' => now(),
        ]);

        return [$inbox, $conversation, $message];
    }

    /** @param array<string,mixed> $payload */
    private function postEvent(
        CommunicationInbox $inbox,
        GatewayEventType $type,
        string $eventId,
        array $payload,
    ) {
        return $this->postSigned([
            'contract_version' => 'v1',
            'gateway_event_id' => $eventId,
            'session_id' => $inbox->session_id,
            'type' => $type->value,
            'occurred_at' => now()->toIso8601String(),
            'payload' => $payload,
        ]);
    }

    /** @param array<string,mixed> $event */
    private function postSigned(array $event)
    {
        $path = '/api/internal/v1/communication/gateway/events';
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = app(HmacSigner::class)->headers('POST', $path, $body);

        return $this->json('POST', $path, $event, $headers, JSON_UNESCAPED_SLASHES);
    }
}
