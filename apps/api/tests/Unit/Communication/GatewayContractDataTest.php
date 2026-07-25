<?php

namespace Tests\Unit\Communication;

use App\DTO\Communication\CommunicationPayloadDigest;
use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayContractPayload;
use App\DTO\Communication\GatewayEventData;
use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\GatewayEventType;
use App\Enums\Communication\GatewayQueryType;
use App\Enums\Communication\SignatureVerificationResult;
use App\Services\Communication\Security\CommunicationHmacCanonicalizer;
use App\Services\Communication\Security\CommunicationHmacSigner;
use App\Services\Communication\Security\CommunicationHmacVerifier;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use InvalidArgumentException;
use Tests\TestCase;

class GatewayContractDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('communication.hmac', [
            'current_key_id' => 'laravel-v2',
            'current_secret' => 'current-test-secret',
            'previous_key_id' => 'laravel-v1',
            'previous_secret' => 'previous-test-secret',
            'window_seconds' => 300,
            'nonce_ttl_seconds' => 600,
        ]);
    }

    public function test_command_contract_has_stable_digest_independent_of_object_key_order(): void
    {
        $left = new GatewayCommandData(
            commandId: 'command-0001',
            sessionId: 'session-0001',
            type: GatewayCommandType::SendMessage,
            payload: ['text' => 'Olá', 'to' => '+5511999991234'],
            providerMessageId: 'message-0001',
        );
        $right = new GatewayCommandData(
            commandId: 'command-0001',
            sessionId: 'session-0001',
            type: GatewayCommandType::SendMessage,
            payload: ['to' => '+5511999991234', 'text' => 'Olá'],
            providerMessageId: 'message-0001',
        );

        $this->assertSame($left->digest(), $right->digest());
        $this->assertSame(64, strlen(CommunicationPayloadDigest::make($left->toArray())));
        $this->assertSame('v1', $left->toArray()['contract_version']);
    }

    public function test_query_contract_has_the_same_versioned_canonical_envelope(): void
    {
        $query = new GatewayQueryData(
            queryId: 'query-user-check-0001',
            sessionId: 'session-0001',
            type: GatewayQueryType::CheckUsers,
            payload: ['users' => ['+5511999991234']],
        );

        $this->assertSame([
            'contract_version' => 'v1',
            'query_id' => 'query-user-check-0001',
            'session_id' => 'session-0001',
            'type' => 'USER_CHECK',
            'payload' => ['users' => ['+5511999991234']],
        ], $query->toArray());
        $this->assertSame(64, strlen($query->digest()));
    }

    public function test_empty_payload_is_encoded_as_a_json_object_for_go_strict_decoding(): void
    {
        $command = new GatewayCommandData(
            commandId: 'command-connect-0001',
            sessionId: 'session-0001',
            type: GatewayCommandType::ConnectSession,
        );
        $query = new GatewayQueryData(
            queryId: 'query-blocklist-0001',
            sessionId: 'session-0001',
            type: GatewayQueryType::Blocklist,
        );

        $this->assertSame('{}', json_encode($command->toArray()['payload'], JSON_THROW_ON_ERROR));
        $this->assertSame('{}', json_encode($query->toArray()['payload'], JSON_THROW_ON_ERROR));
    }

    public function test_query_uses_the_same_hmac_window_and_replay_protection_as_commands(): void
    {
        $query = new GatewayQueryData(
            queryId: 'query-user-check-0002',
            sessionId: 'session-0001',
            type: GatewayQueryType::CheckUsers,
            payload: ['users' => ['+5511999991234']],
        );
        $body = json_encode($query->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = 1_785_000_000;
        $nonce = 'query-replay-nonce-0001';
        $path = '/internal/v1/queries';
        $headers = app(CommunicationHmacSigner::class)->headers('POST', $path, $body, $timestamp, $nonce);
        $verifier = new CommunicationHmacVerifier(
            app(CommunicationHmacCanonicalizer::class),
            app(CacheRepository::class),
        );

        $this->assertSame(
            SignatureVerificationResult::Valid,
            $verifier->verify('POST', $path, $body, $headers, $timestamp + 1),
        );
        $this->assertSame(
            SignatureVerificationResult::Replay,
            $verifier->verify('POST', $path, $body, $headers, $timestamp + 2),
        );
    }

    public function test_php_payload_catalog_covers_every_command_and_query_enum(): void
    {
        $this->assertEqualsCanonicalizing(
            array_column(GatewayCommandType::cases(), 'value'),
            GatewayContractPayload::commandTypeValues(),
        );
        $this->assertEqualsCanonicalizing(
            array_column(GatewayQueryType::cases(), 'value'),
            GatewayContractPayload::queryTypeValues(),
        );
    }

    public function test_unknown_or_protocol_sensitive_fields_fail_before_transport(): void
    {
        try {
            new GatewayCommandData(
                commandId: 'command-invalid-0001',
                sessionId: 'session-0001',
                type: GatewayCommandType::SendMessage,
                payload: ['to' => '+5511999991234', 'raw_proto' => ['field' => true]],
                providerMessageId: 'message-invalid-0001',
            );
            $this->fail('Campo desconhecido do comando deveria ser rejeitado.');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('Campo desconhecido', $error->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Campo sensível não permitido');
        new GatewayEventData(
            gatewayEventId: 'gateway-event-invalid-0001',
            sessionId: 'session-0001',
            type: GatewayEventType::MessageReceived,
            occurredAt: new DateTimeImmutable('2026-07-22T12:00:00Z'),
            payload: ['provider_message_id' => 'message-0001', 'media_key' => 'secret'],
        );
    }

    public function test_commands_that_send_a_remote_message_require_laravel_provider_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('provider_message_id obrigatório para MESSAGE_REVOKE');

        new GatewayCommandData(
            commandId: 'command-revoke-0001',
            sessionId: 'session-0001',
            type: GatewayCommandType::RevokeMessage,
            payload: ['to' => '+5511999991234', 'target_message_id' => 'message-target-0001'],
        );
    }

    public function test_cataloged_rich_message_and_played_receipt_match_gateway_shapes(): void
    {
        $event = new GatewayEventData(
            gatewayEventId: 'gateway-rich-contact-0001',
            sessionId: 'session-0001',
            type: GatewayEventType::MessageReceived,
            occurredAt: new DateTimeImmutable('2026-07-24T12:00:00Z'),
            payload: [
                'provider_message_id' => 'message-rich-contact-0001',
                'from' => '+5511999991234',
                'kind' => 'CONTACT',
                'provider_type' => 'contactsArrayMessage',
                'family' => 'CONTACT',
                'contacts' => [
                    ['display_name' => '', 'vcard' => "BEGIN:VCARD\nFN:Cliente\nEND:VCARD"],
                ],
            ],
        );
        $this->assertSame('contactsArrayMessage', $event->payload['provider_type']);

        $receipt = new GatewayEventData(
            gatewayEventId: 'gateway-played-receipt-0001',
            sessionId: 'session-0001',
            type: GatewayEventType::MessageStatusChanged,
            occurredAt: new DateTimeImmutable('2026-07-24T12:01:00Z'),
            payload: ['provider_message_id' => 'message-rich-contact-0001', 'status' => 'PLAYED'],
        );
        $this->assertSame('PLAYED', $receipt->payload['status']);
    }

    public function test_actions_and_media_retry_are_closed_and_reject_unknown_fields(): void
    {
        new GatewayEventData(
            gatewayEventId: 'gateway-edit-contract-0001',
            sessionId: 'session-0001',
            type: GatewayEventType::MessageActionReceived,
            occurredAt: new DateTimeImmutable('2026-07-24T12:00:00Z'),
            payload: [
                'action' => 'EDIT',
                'provider_message_id' => 'message-edit-event-0001',
                'target_message_id' => 'message-target-event-0001',
                'from' => '+5511999991234',
                'kind' => 'TEXT',
                'provider_type' => 'conversation',
                'family' => 'TEXT',
                'text' => 'Editado',
            ],
        );
        new GatewayEventData(
            gatewayEventId: 'gateway-media-ready-0001',
            sessionId: 'session-0001',
            type: GatewayEventType::MediaRetryUpdated,
            occurredAt: new DateTimeImmutable('2026-07-24T12:00:00Z'),
            payload: [
                'provider_message_id' => 'message-target-event-0001',
                'status' => 'READY',
                'generation' => 2,
                'attempt' => 3,
                'spool_id' => 'spool-media-ready-0001',
                'size_bytes' => 4,
                'sha256' => hash('sha256', 'data'),
                'mime_type' => 'image/png',
                'filename' => 'foto.png',
            ],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Campo desconhecido em MESSAGE_ACTION_RECEIVED');
        new GatewayEventData(
            gatewayEventId: 'gateway-action-unknown-0001',
            sessionId: 'session-0001',
            type: GatewayEventType::MessageActionReceived,
            occurredAt: new DateTimeImmutable('2026-07-24T12:00:00Z'),
            payload: [
                'action' => 'REVOKE',
                'target_message_id' => 'message-target-event-0001',
                'from' => '+5511999991234',
                'unexpected' => true,
            ],
        );
    }

    public function test_query_payload_rejects_fields_outside_its_type_allowlist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Campo desconhecido em query PROFILE_PICTURE');

        new GatewayQueryData(
            queryId: 'query-picture-0001',
            sessionId: 'session-0001',
            type: GatewayQueryType::ProfilePicture,
            payload: ['user' => '+5511999991234', 'device_jid' => true],
        );
    }

    public function test_composed_action_and_presence_fields_are_allowlisted_without_raw_protocol_data(): void
    {
        $reaction = new GatewayCommandData(
            commandId: 'command-reaction-0001',
            sessionId: 'session-0001',
            type: GatewayCommandType::ReactMessage,
            payload: [
                'to' => '+5511999991234',
                'target_message_id' => 'message-target-0001',
                'sender' => '+5511988884321',
                'emoji' => '✅',
            ],
            providerMessageId: 'message-reaction-0001',
        );
        $mark = new GatewayCommandData(
            commandId: 'command-mark-0001',
            sessionId: 'session-0001',
            type: GatewayCommandType::MarkMessage,
            payload: [
                'to' => '+5511999991234',
                'message_ids' => ['message-target-0001'],
                'receipt' => 'READ',
                'timestamp' => 1_785_000_000,
                'protocol' => true,
            ],
        );
        $presence = new GatewayCommandData(
            commandId: 'command-presence-0001',
            sessionId: 'session-0001',
            type: GatewayCommandType::SetPresence,
            payload: ['presence' => 'AVAILABLE', 'force_active_delivery_receipts' => false],
        );
        $chatState = new GatewayCommandData(
            commandId: 'command-chat-state-0001',
            sessionId: 'session-0001',
            type: GatewayCommandType::UpdateChatState,
            payload: [
                'to' => '+5511999991234',
                'action' => 'DELETE_CHAT',
                'sender' => '+5511988884321',
                'timestamp' => 1_785_000_000,
                'duration_seconds' => 3600,
                'delete_media' => true,
                'from_me' => false,
            ],
        );
        $history = new GatewayCommandData(
            commandId: 'command-history-0001',
            sessionId: 'session-0001',
            type: GatewayCommandType::RequestHistorySync,
            payload: [
                'to' => '+5511999991234',
                'last_message_id' => 'message-target-0001',
                'last_message_from' => '+5511988884321',
                'last_message_timestamp' => 1_785_000_000,
                'last_message_from_me' => false,
                'count' => 50,
            ],
        );
        $mediaRetry = new GatewayCommandData(
            commandId: 'command-media-retry-0001',
            sessionId: 'session-0001',
            type: GatewayCommandType::RequestMediaRetry,
            payload: [
                'to' => '+5511999991234',
                'target_message_id' => 'message-target-0001',
                'sender' => '+5511988884321',
                'from_me' => false,
            ],
        );

        $this->assertSame('✅', $reaction->toArray()['payload']['emoji']);
        $this->assertTrue($mark->toArray()['payload']['protocol']);
        $this->assertFalse($presence->toArray()['payload']['force_active_delivery_receipts']);
        $this->assertSame('DELETE_CHAT', $chatState->toArray()['payload']['action']);
        $this->assertSame(1_785_000_000, $history->toArray()['payload']['last_message_timestamp']);
        $this->assertFalse($mediaRetry->toArray()['payload']['from_me']);

        $sync = new GatewayCommandData(
            commandId: 'command-chat-sync-0001',
            sessionId: 'session-0001',
            type: GatewayCommandType::UpdateChatState,
            payload: ['action' => 'SYNC'],
        );
        $markClean = new GatewayCommandData(
            commandId: 'command-mark-clean-0001',
            sessionId: 'session-0001',
            type: GatewayCommandType::UpdateChatState,
            payload: ['action' => 'MARK_CLEAN', 'timestamp' => 1_785_000_000],
        );
        $this->assertSame('SYNC', $sync->toArray()['payload']['action']);
        $this->assertSame(1_785_000_000, $markClean->toArray()['payload']['timestamp']);
    }

    public function test_all_one_to_one_contract_families_remain_explicit_enums(): void
    {
        $this->assertContains('MESSAGE_EDIT', array_column(GatewayCommandType::cases(), 'value'));
        $this->assertContains('HISTORY_SYNC_REQUEST', array_column(GatewayCommandType::cases(), 'value'));
        $this->assertContains('PRIVACY_SETTINGS', array_column(GatewayQueryType::cases(), 'value'));
    }
}
