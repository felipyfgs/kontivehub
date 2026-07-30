<?php

namespace Tests\Feature\Communication;

use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayCommandReceipt;
use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\GatewayEventType;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\CommunicationChannel;
use App\Exceptions\CommunicationTransportException;
use App\Http\Resources\Communication\CommunicationMessageResource;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationConversationUnreadMessage;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Communication\Conversation\CommunicationConversationMessageQuery;
use App\Services\Communication\Outbox\CommunicationOutboxDispatcher;
use App\Services\Communication\Security\CommunicationHmacSigner;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

final class CommunicationMessageLossPreventionTest extends TestCase
{
    use RefreshDatabase;

    private MessageLossTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
            'communication.hmac.current_key_id' => 'message-loss-key',
            'communication.hmac.current_secret' => str_repeat('m', 32),
            'communication.media.disk_root' => sys_get_temp_dir().'/communication-message-loss-'.Str::ulid(),
            'communication.history_media_recovery.enabled' => true,
            'communication.history_media_recovery.kill_switch' => false,
            'communication.history_media_recovery.max_batch' => 2,
            'communication.history_media_recovery.session_limit' => 2,
            'communication.history_media_recovery.backoff_seconds' => 1,
        ]);
        $this->transport = new MessageLossTransport;
        $this->app->instance(CommunicationTransport::class, $this->transport);
    }

    public function test_history_media_is_explicit_and_duplicate_enriches_without_recreating_unread(): void
    {
        [$tenant, $inbox] = $this->context();
        $address = '+5511999998101';
        $history = $this->event($inbox, GatewayEventType::HistorySynced, 'gateway-history-loss-0001', [
            'batch_id' => 'history-loss-batch-0001',
            'complete' => true,
            'messages' => [
                [
                    'provider_message_id' => 'provider-history-inbound-0001',
                    'from' => $address,
                    'direction' => 'INBOUND',
                    'kind' => 'IMAGE',
                    'provider_type' => 'imageMessage',
                    'family' => 'IMAGE',
                    'media_state' => 'RETRY_AVAILABLE',
                ],
                [
                    'provider_message_id' => 'provider-history-outbound-0001',
                    'from' => $address,
                    'direction' => 'OUTBOUND',
                    'kind' => 'DOCUMENT',
                    'provider_type' => 'documentMessage',
                    'family' => 'DOCUMENT',
                    'media_state' => 'RETRY_AVAILABLE',
                ],
            ],
        ]);
        $this->postSignedEvent($history)->assertNoContent();

        $messages = CommunicationMessage::query()->withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertSame(['INBOUND', 'OUTBOUND'], $messages->map(
            static fn (CommunicationMessage $message): string => $message->direction->value,
        )->all());
        foreach ($messages as $message) {
            $resource = (new CommunicationMessageResource($message->load('attachments')))->resolve();
            $this->assertSame('MEDIA_RETRY_AVAILABLE', $resource['availability']['state']);
            $this->assertTrue($resource['availability']['recoverable']);
            $this->assertNull($resource['body']);
            $this->assertCount(0, $resource['attachments']);
        }

        $inbound = $messages->first();
        $bytes = 'historical-image-recovered';
        $this->transport->media['spool-history-enrichment-0001'] = $bytes;
        $enrichment = $this->event($inbox, GatewayEventType::MessageReceived, 'gateway-history-enrichment-0001', [
            'provider_message_id' => 'provider-history-inbound-0001',
            'from' => $address,
            'direction' => 'INBOUND',
            'history' => true,
            'kind' => 'IMAGE',
            'provider_type' => 'imageMessage',
            'family' => 'IMAGE',
            'caption' => 'Comprovante recuperado',
            'spool_id' => 'spool-history-enrichment-0001',
            'media_size_bytes' => strlen($bytes),
            'media_sha256' => hash('sha256', $bytes),
            'mime_type' => 'image/png',
            'filename' => 'comprovante.png',
        ]);
        $this->postSignedEvent($enrichment)->assertNoContent();

        $inbound->refresh();
        $this->assertDatabaseCount('communication_messages', 2);
        $this->assertSame('Comprovante recuperado', $inbound->body_encrypted);
        $this->assertSame('Comprovante recuperado', $inbound->content_encrypted['caption']);
        $this->assertSame('READY', $inbound->metadata['media_state']);
        $this->assertSame(1, $inbound->attachments()->withoutGlobalScopes()->count());
        $this->assertDatabaseCount('communication_conversation_unread_messages', 0);

        $partial = $enrichment;
        $partial['gateway_event_id'] = 'gateway-history-enrichment-0002';
        unset(
            $partial['payload']['caption'],
            $partial['payload']['spool_id'],
            $partial['payload']['media_size_bytes'],
            $partial['payload']['media_sha256'],
            $partial['payload']['mime_type'],
            $partial['payload']['filename'],
        );
        $partial['payload']['media_state'] = 'RETRY_AVAILABLE';
        $this->postSignedEvent($partial)->assertNoContent();
        $this->assertSame('Comprovante recuperado', $inbound->refresh()->body_encrypted);
        $this->assertSame('READY', $inbound->metadata['media_state']);

        $unavailable = $partial;
        $unavailable['gateway_event_id'] = 'gateway-history-enrichment-0003';
        $unavailable['payload']['media_state'] = 'UNAVAILABLE';
        $unavailable['payload']['media_error_code'] = 'MEDIA_NOT_AVAILABLE';
        $this->postSignedEvent($unavailable)->assertNoContent();
        $inbound->refresh();
        $this->assertSame('READY', $inbound->metadata['media_state']);
        $this->assertArrayNotHasKey('media_error_code', $inbound->metadata);
        $this->assertSame(1, $inbound->attachments()->withoutGlobalScopes()->count());

        $conflict = $partial;
        $conflict['gateway_event_id'] = 'gateway-history-enrichment-0004';
        $conflict['payload']['caption'] = 'Conteúdo divergente';
        $this->postSignedEvent($conflict)
            ->assertStatus(409)
            ->assertJson(['error' => 'EVENT_DIGEST_CONFLICT']);
        $this->assertSame('Comprovante recuperado', $inbound->refresh()->body_encrypted);
        $this->assertDatabaseCount('communication_attachments', 1);
        $this->assertSame($tenant->id, $inbound->tenant_id);
    }

    public function test_quarantine_is_dry_run_tenant_safe_reversible_and_excluded_from_timeline(): void
    {
        [$tenant, $inbox, $identity, $conversation] = $this->contextWithConversation();
        [$foreignTenant, $foreignInbox, $foreignIdentity, $foreignConversation] = $this->contextWithConversation();
        $visible = $this->message($tenant, $inbox, $identity, $conversation, 'provider-visible-0001', MessageKind::Text);
        $control = $this->message(
            $tenant,
            $inbox,
            $identity,
            $conversation,
            'provider-sensitive-control-0001',
            MessageKind::Unsupported,
            'protocolMessage',
            null,
        );
        $foreign = $this->message(
            $foreignTenant,
            $foreignInbox,
            $foreignIdentity,
            $foreignConversation,
            'provider-foreign-control-0001',
            MessageKind::Unsupported,
            'protocolMessage',
            null,
        );
        CommunicationConversationUnreadMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'message_id' => $control->id,
        ]);
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();
        $options = [
            '--tenant' => $tenant->id,
            '--inbox' => $inbox->id,
            '--operation' => 'projection-operation-0001',
        ];

        $this->assertSame(0, Artisan::call('communication:audit-message-projection', $options));
        $dryRun = Artisan::output();
        $this->assertStringContainsString('"mode":"dry-run"', $dryRun);
        $this->assertStringNotContainsString('provider-sensitive', $dryRun);
        $this->assertNull($control->refresh()->quarantined_at);

        $this->assertSame(0, Artisan::call('communication:audit-message-projection', [
            ...$options,
            '--execute' => true,
            '--actor' => $actor->id,
        ]));
        $control->refresh();
        $this->assertNotNull($control->quarantined_at);
        $this->assertSame('WHATSAPP_PROTOCOL_CONTROL', $control->quarantine_reason);
        $this->assertSame('projection-operation-0001', $control->quarantine_operation_id);
        $this->assertNull($foreign->refresh()->quarantined_at);

        $page = app(CommunicationConversationMessageQuery::class)->paginate($conversation, 50);
        $this->assertSame([$visible->id], $page['data']->pluck('id')->all());
        $this->assertSame(0, $page['meta']['unread_count']);
        $this->assertNull($page['meta']['first_unread_message_id']);

        $this->assertSame(0, Artisan::call('communication:audit-message-projection', [
            ...$options,
            '--reverse' => 'projection-operation-0001',
        ]));
        $this->assertNotNull($control->refresh()->quarantined_at);
        $this->assertSame(0, Artisan::call('communication:audit-message-projection', [
            ...$options,
            '--reverse' => 'projection-operation-0001',
            '--execute' => true,
            '--actor' => $actor->id,
        ]));
        $this->assertNull($control->refresh()->quarantined_at);
        $this->assertNull($control->quarantine_reason);
        $this->assertNull($control->quarantine_operation_id);
    }

    public function test_media_rescue_is_dry_run_bounded_direction_aware_and_idempotent(): void
    {
        [$tenant, $inbox, $identity, $conversation] = $this->contextWithConversation();
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();
        $inbound = $this->historicalMedia($tenant, $inbox, $identity, $conversation, 'inbound', MessageDirection::Inbound, MessageKind::Image);
        $outbound = $this->historicalMedia($tenant, $inbox, $identity, $conversation, 'outbound', MessageDirection::Outbound, MessageKind::Document);
        $expired = $this->historicalMedia($tenant, $inbox, $identity, $conversation, 'expired', MessageDirection::Inbound, MessageKind::Video);
        $expired->forceFill(['metadata' => [
            'history' => true,
            'media_state' => 'FAILED',
            'media_error_code' => 'MEDIA_RETRY_DESCRIPTOR_EXPIRED',
        ]])->save();
        $expiredAvailability = (new CommunicationMessageResource($expired->load('attachments')))->resolve()['availability'];
        $this->assertSame('MEDIA_FAILED', $expiredAvailability['state']);
        $this->assertFalse($expiredAvailability['recoverable']);
        $options = [
            '--tenant' => $tenant->id,
            '--inbox' => $inbox->id,
            '--limit' => 2,
            '--operation' => 'media-rescue-operation-0001',
        ];

        $this->assertSame(0, Artisan::call('communication:rescue-history-media', $options));
        $dryRun = Artisan::output();
        $this->assertStringContainsString('"eligible_count":2', $dryRun);
        $this->assertStringContainsString('"INBOUND":1', $dryRun);
        $this->assertStringContainsString('"OUTBOUND":1', $dryRun);
        $this->assertStringNotContainsString('provider-', $dryRun);
        $this->assertDatabaseCount('communication_outbox_entries', 0);

        $this->assertSame(0, Artisan::call('communication:rescue-history-media', [
            ...$options,
            '--execute' => true,
            '--actor' => $actor->id,
        ]));
        $entries = CommunicationOutboxEntry::query()->withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame([$inbound->id, $outbound->id], $entries->pluck('message_id')->all());
        $this->assertSame(['INBOUND', 'OUTBOUND'], $entries->pluck('payload_encrypted.expected_direction')->all());
        foreach ($entries as $entry) {
            $this->assertArrayNotHasKey('sender', $entry->payload_encrypted);
            $this->assertArrayNotHasKey('from_me', $entry->payload_encrypted);
        }

        app(CommunicationOutboxDispatcher::class)->dispatch((int) $entries[0]->id);
        $this->transport->failures[$entries[1]->command_id] = new CommunicationTransportException('GATEWAY_TEMPORARY', false);
        app(CommunicationOutboxDispatcher::class)->dispatch((int) $entries[1]->id);
        $this->assertSame('REQUESTED', $inbound->refresh()->metadata['media_state']);
        $this->assertSame(MessageStatus::Delivered, $inbound->status);
        $this->assertSame('FAILED', $outbound->refresh()->metadata['media_state']);
        $this->assertSame('MEDIA_RETRY_REQUEST_FAILED', $outbound->metadata['media_error_code']);
        $this->assertSame(MessageStatus::Sent, $outbound->status);
        $this->assertSame('FAILED', $expired->refresh()->metadata['media_state']);

        $this->assertSame(0, Artisan::call('communication:rescue-history-media', [
            ...$options,
            '--execute' => true,
            '--actor' => $actor->id,
        ]));
        $this->assertStringContainsString('"blocked_code":"PENDING_RESULT"', Artisan::output());
        $this->assertDatabaseCount('communication_outbox_entries', 2);

        $inbound->forceFill(['metadata' => [
            'history' => true,
            'media_state' => 'UNAVAILABLE',
        ]])->save();
        CommunicationOutboxEntry::query()->withoutGlobalScopes()->update([
            'created_at' => now()->subMinutes(10),
        ]);
        $this->assertSame(0, Artisan::call('communication:rescue-history-media', [
            ...$options,
            '--execute' => true,
            '--actor' => $actor->id,
        ]));
        $this->assertStringContainsString('"requested_count":1', Artisan::output());
        $this->assertDatabaseCount('communication_outbox_entries', 3);
        $secondAttempt = CommunicationOutboxEntry::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame($outbound->id, $secondAttempt->message_id);
        $this->assertStringEndsWith(':2', $secondAttempt->effect_key);

        $this->assertSame(0, Artisan::call('communication:rescue-history-media', [
            ...$options,
            '--execute' => true,
            '--actor' => $actor->id,
        ]));
        $this->assertStringContainsString('"blocked_code":"PENDING_RESULT"', Artisan::output());
        $this->assertDatabaseCount('communication_outbox_entries', 3);
    }

    public function test_legacy_history_media_without_state_is_inventory_only_until_privileged_execute(): void
    {
        [$tenant, $inbox, $identity, $conversation] = $this->contextWithConversation();
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();
        $withoutCaption = $this->message(
            $tenant,
            $inbox,
            $identity,
            $conversation,
            'provider-legacy-without-caption-0001',
            MessageKind::Image,
            'imageMessage',
            null,
        );
        $withoutCaption->forceFill(['metadata' => ['history' => true]])->save();
        $withCaption = $this->message(
            $tenant,
            $inbox,
            $identity,
            $conversation,
            'provider-legacy-with-caption-0001',
            MessageKind::Document,
            'documentMessage',
            'Legenda preservada',
            MessageDirection::Outbound,
        );
        $withCaption->forceFill([
            'content_encrypted' => ['caption' => 'Legenda preservada'],
            'metadata' => ['history' => true],
        ])->save();
        foreach ([$withoutCaption, $withCaption] as $legacy) {
            $availability = (new CommunicationMessageResource($legacy->load('attachments')))->resolve()['availability'];
            $this->assertSame('UNAVAILABLE', $availability['state']);
            $this->assertFalse($availability['recoverable']);
        }

        $options = [
            '--tenant' => $tenant->id,
            '--inbox' => $inbox->id,
            '--limit' => 2,
            '--operation' => 'media-rescue-legacy-0001',
        ];
        $this->assertSame(0, Artisan::call('communication:rescue-history-media', $options));
        $this->assertStringContainsString('"eligible_count":2', Artisan::output());
        $this->assertDatabaseCount('communication_outbox_entries', 0);

        $this->assertSame(0, Artisan::call('communication:rescue-history-media', [
            ...$options,
            '--execute' => true,
            '--actor' => $actor->id,
        ]));
        $entries = CommunicationOutboxEntry::query()->withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(['INBOUND', 'OUTBOUND'], $entries->pluck('payload_encrypted.expected_direction')->all());
        $this->assertTrue($entries->every(
            static fn (CommunicationOutboxEntry $entry): bool => str_starts_with((string) $entry->effect_key, 'media-rescue:'),
        ));

        app(CommunicationOutboxDispatcher::class)->dispatch((int) $entries->first()->id);
        $this->assertSame('REQUESTED', $withoutCaption->refresh()->metadata['media_state']);
    }

    /** @return array{Tenant,CommunicationInbox} */
    private function context(): array
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Inbox message loss',
            'session_id' => 'session-'.strtolower((string) Str::ulid()),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
        ]);

        return [$tenant, $inbox];
    }

    /** @return array{Tenant,CommunicationInbox,CommunicationIdentity,CommunicationConversation} */
    private function contextWithConversation(): array
    {
        [$tenant, $inbox] = $this->context();
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Contato message loss',
            'is_active' => true,
        ]);
        $address = '+551199999'.str_pad((string) $tenant->id, 4, '0', STR_PAD_LEFT);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::Whatsapp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => '***'.substr($address, -4),
            'is_active' => true,
        ]);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ]);

        return [$tenant, $inbox, $identity, $conversation];
    }

    private function historicalMedia(
        Tenant $tenant,
        CommunicationInbox $inbox,
        CommunicationIdentity $identity,
        CommunicationConversation $conversation,
        string $suffix,
        MessageDirection $direction,
        MessageKind $kind,
    ): CommunicationMessage {
        $message = $this->message(
            $tenant,
            $inbox,
            $identity,
            $conversation,
            'provider-history-'.$suffix.'-0001',
            $kind,
            strtolower($kind->value).'Message',
            null,
            $direction,
        );
        $message->forceFill(['metadata' => [
            'history' => true,
            'media_state' => 'RETRY_AVAILABLE',
        ]])->save();

        return $message;
    }

    private function message(
        Tenant $tenant,
        CommunicationInbox $inbox,
        CommunicationIdentity $identity,
        CommunicationConversation $conversation,
        string $providerId,
        MessageKind $kind,
        string $providerType = 'conversation',
        ?string $body = 'Mensagem visível',
        MessageDirection $direction = MessageDirection::Inbound,
    ): CommunicationMessage {
        return CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $identity->id,
            'direction' => $direction,
            'kind' => $kind,
            'provider_type' => $providerType,
            'source' => MessageSource::Gateway,
            'status' => $direction === MessageDirection::Inbound ? MessageStatus::Delivered : MessageStatus::Sent,
            'body_encrypted' => $body,
            'content_encrypted' => $body !== null ? ['text' => $body] : null,
            'provider_message_id' => $providerId,
            'content_digest' => hash('sha256', $providerId),
            'occurred_at' => now()->addSeconds((int) $conversation->messages()->withoutGlobalScopes()->count()),
        ]);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function event(
        CommunicationInbox $inbox,
        GatewayEventType $type,
        string $gatewayEventId,
        array $payload,
    ): array {
        return [
            'contract_version' => 'v1',
            'gateway_event_id' => $gatewayEventId,
            'session_id' => $inbox->session_id,
            'type' => $type->value,
            'occurred_at' => now()->toIso8601String(),
            'payload' => $payload,
        ];
    }

    /** @param array<string,mixed> $event */
    private function postSignedEvent(array $event)
    {
        $path = '/api/internal/v1/communication/gateway/events';
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = app(CommunicationHmacSigner::class)->headers('POST', $path, $body);

        return $this->json('POST', $path, $event, $headers, JSON_UNESCAPED_SLASHES);
    }
}

final class MessageLossTransport implements CommunicationTransport
{
    /** @var array<string,string> */
    public array $media = [];

    /** @var array<string,CommunicationTransportException> */
    public array $failures = [];

    public function dispatch(GatewayCommandData $command): GatewayCommandReceipt
    {
        if (isset($this->failures[$command->commandId])) {
            throw $this->failures[$command->commandId];
        }

        return new GatewayCommandReceipt($command->commandId, false);
    }

    public function query(GatewayQueryData $query): array
    {
        return ['type' => $query->type->value];
    }

    public function sessionStatus(string $sessionId): array
    {
        return ['session_id' => $sessionId, 'status' => InboxStatus::Connected->value];
    }

    public function downloadMedia(string $spoolId): StreamInterface
    {
        return Utils::streamFor($this->media[$spoolId] ?? '');
    }
}
