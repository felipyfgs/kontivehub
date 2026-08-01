<?php

namespace Tests\Feature\Communication;

use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayCommandReceipt;
use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\FlowRunStatus;
use App\Enums\Communication\FlowStatus;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\GatewayEventType;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\Communication\OutboxStatus;
use App\Enums\Communication\ProfilePictureState;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDispatchStatus;
use App\Enums\CommunicationExecutionMode;
use App\Events\CommunicationEventCommitted;
use App\Exceptions\CommunicationTransportException;
use App\Jobs\Communication\DeleteMediaObjectJob;
use App\Models\Client;
use App\Models\ClientCommunicationDispatch;
use App\Models\ClientCommunicationEvent;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationConversationUnreadMessage;
use App\Models\CommunicationEvent;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowRun;
use App\Models\CommunicationFlowVersion;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxIdentityProfile;
use App\Models\CommunicationLabel;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Models\Tenant;
use App\Services\Communication\Conversation\ConversationReadStateService;
use App\Services\Communication\Media\MediaStore;
use App\Services\Communication\Outbox\OutboxDispatcher;
use App\Services\Communication\Security\HmacSigner;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

final class CommunicationGatewayFlowTest extends TestCase
{
    use RefreshDatabase;

    private FakeCommunicationTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
            'communication.hmac.current_key_id' => 'test-key',
            'communication.hmac.current_secret' => str_repeat('h', 32),
            'communication.media.disk_root' => sys_get_temp_dir().'/communication-gateway-tests-'.Str::ulid(),
        ]);
        Event::fake([CommunicationEventCommitted::class]);
        $this->transport = new FakeCommunicationTransport;
        $this->app->instance(CommunicationTransport::class, $this->transport);
    }

    public function test_signed_inbound_is_idempotent_creates_provisional_timeline_and_rejects_conflict(): void
    {
        $this->app->instance('env', 'local');
        [, $inbox] = $this->context();
        $bytes = '%PDF-inbound-private';
        $event = $this->event($inbox, GatewayEventType::MessageReceived, 'gateway-inbound-0001', [
            'provider_message_id' => 'provider-inbound-0001',
            'from' => '+5511999990001',
            'kind' => 'DOCUMENT',
            'provider_type' => 'documentMessage',
            'family' => 'DOCUMENT',
            'text' => 'Documento enviado',
            'spool_id' => 'spool-inbound-0001',
            'media_sha256' => hash('sha256', $bytes),
            'media_size_bytes' => strlen($bytes),
            'mime_type' => 'application/pdf',
            'filename' => '../comprovante.pdf',
        ]);
        $this->transport->media['spool-inbound-0001'] = $bytes;

        $this->postJson('/api/internal/v1/communication/gateway/events', $event)
            ->assertUnauthorized()
            ->assertJson(['error' => 'INVALID_INTERNAL_SIGNATURE']);
        $this->postSignedEvent($event)->assertNoContent()->assertHeader('X-Communication-Result', 'processed');
        $this->postSignedEvent($event)->assertNoContent()->assertHeader('X-Communication-Result', 'duplicate');

        $conflicting = $event;
        $conflicting['payload']['text'] = 'Conteúdo conflitante';
        $this->postSignedEvent($conflicting)->assertStatus(409)->assertJson(['error' => 'EVENT_DIGEST_CONFLICT']);

        $this->assertDatabaseCount('communication_contacts', 1);
        $this->assertDatabaseHas('communication_contacts', ['is_provisional' => true]);
        $this->assertDatabaseCount('communication_identities', 1);
        $this->assertDatabaseCount('communication_conversations', 1);
        $this->assertDatabaseHas('communication_conversations', ['status' => ConversationStatus::Open->value]);
        $this->assertDatabaseCount('communication_messages', 1);
        $this->assertDatabaseCount('communication_attachments', 1);
        $this->assertDatabaseCount('communication_conversation_unread_messages', 1);
        $this->assertDatabaseCount('communication_conversation_read_states', 1);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->sole();
        $this->assertSame(1, CommunicationEvent::query()->withoutGlobalScopes()
            ->where('gateway_event_id', 'gateway-inbound-0001')->count());
        $this->assertDatabaseCount('communication_events', 3);
        $this->assertUnavailableProfilePictureEvent($inbox, $identity);
        $attachment = CommunicationAttachment::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(hash('sha256', $bytes), $attachment->sha256);
        $this->assertSame('comprovante.pdf', $attachment->original_name_encrypted);
        $this->assertSame(1, $this->transport->downloadCalls);
    }

    public function test_duplicate_live_inbound_does_not_recreate_unread_after_read(): void
    {
        [, $inbox] = $this->context();
        $event = $this->event($inbox, GatewayEventType::MessageReceived, 'gateway-live-read-retry-0001', [
            'provider_message_id' => 'provider-live-read-retry-0001',
            'from' => '+5511999990044',
            'direction' => 'INBOUND',
            'kind' => 'TEXT',
            'provider_type' => 'conversation',
            'family' => 'TEXT',
            'text' => 'Mensagem live única',
        ]);
        $this->postSignedEvent($event)->assertNoContent();
        $message = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('provider_message_id', 'provider-live-read-retry-0001')
            ->sole();
        DB::transaction(function () use ($message): void {
            $conversation = CommunicationConversation::query()->withoutGlobalScopes()
                ->whereKey($message->conversation_id)
                ->lockForUpdate()
                ->firstOrFail();
            app(ConversationReadStateService::class)->markRead(
                $conversation,
                (int) $message->id,
                null,
                null,
            );
        });
        $this->assertDatabaseCount('communication_conversation_unread_messages', 0);

        $this->postSignedEvent($event)
            ->assertNoContent()
            ->assertHeader('X-Communication-Result', 'duplicate');
        $this->assertDatabaseCount('communication_conversation_unread_messages', 0);
    }

    public function test_lid_and_remote_pn_converge_without_materializing_the_session_pn(): void
    {
        [$tenant, $inbox] = $this->context();
        $sessionPn = '+559981769536';
        $remotePn = '+559992032709';
        $lid = 'lid:149865032093945';
        $inbox->forceFill([
            'address_encrypted' => $sessionPn,
            'address_hash' => hash('sha256', $sessionPn),
            'address_masked' => '***9536',
        ])->save();

        $outbound = $this->event($inbox, GatewayEventType::MessageReceived, 'gateway-lid-outbound-0001', [
            'provider_message_id' => 'provider-lid-outbound-0001',
            'from' => $lid,
            'source_identity' => [
                'primary' => $lid,
                'primary_kind' => 'LID',
                'alternate' => $sessionPn,
                'alternate_kind' => 'PN',
                'evidence' => 'MESSAGE_SOURCE_ALT',
            ],
            'direction' => 'OUTBOUND',
            'kind' => 'TEXT',
            'provider_type' => 'conversation',
            'family' => 'TEXT',
            'text' => 'Enviada pelo aparelho',
        ]);
        $inbound = $this->event($inbox, GatewayEventType::MessageReceived, 'gateway-lid-inbound-0001', [
            'provider_message_id' => 'provider-lid-inbound-0001',
            'from' => $lid,
            'source_identity' => [
                'primary' => $lid,
                'primary_kind' => 'LID',
                'alternate' => $remotePn,
                'alternate_kind' => 'PN',
                'evidence' => 'MESSAGE_SOURCE_ALT',
            ],
            'direction' => 'INBOUND',
            'kind' => 'TEXT',
            'provider_type' => 'conversation',
            'family' => 'TEXT',
            'text' => 'Resposta do cliente',
        ]);

        $this->postSignedEvent($outbound)->assertNoContent();
        $this->postSignedEvent($inbound)->assertNoContent();

        $lidIdentity = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('address_hash', hash('sha256', $lid))
            ->firstOrFail();
        $remoteIdentity = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('address_hash', hash('sha256', $remotePn))
            ->firstOrFail();
        $conversationIds = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->pluck('conversation_id')
            ->unique()
            ->values();

        $this->assertSame($lidIdentity->contact_id, $remoteIdentity->contact_id);
        $this->assertCount(1, $conversationIds);
        $this->assertDatabaseCount('communication_contacts', 1);
        $this->assertDatabaseCount('communication_conversations', 1);
        $this->assertDatabaseMissing('communication_identities', [
            'tenant_id' => $tenant->id,
            'address_hash' => hash('sha256', $sessionPn),
        ]);
    }

    public function test_fragmented_lid_pn_and_self_chat_converge_to_one_active_remote_conversation(): void
    {
        [$tenant, $inbox] = $this->context();
        $sessionPn = '+559981769536';
        $remotePn = '+559992032709';
        $lid = 'lid:149865032093945';
        $inbox->forceFill([
            'address_encrypted' => $sessionPn,
            'address_hash' => hash('sha256', $sessionPn),
            'address_masked' => '***9536',
        ])->save();

        [$lidIdentity, $lidConversation] = $this->identityAndConversationForAddress(
            $tenant,
            $inbox,
            $lid,
            'lid',
        );
        [$remoteIdentity, $remoteConversation] = $this->identityAndConversationForAddress(
            $tenant,
            $inbox,
            $remotePn,
            'remote',
        );
        $lidContact = CommunicationContact::query()->withoutGlobalScopes()
            ->findOrFail($lidIdentity->contact_id);
        $remoteContact = CommunicationContact::query()->withoutGlobalScopes()
            ->findOrFail($remoteIdentity->contact_id);
        $lidContact->forceFill([
            'name' => 'Cliente curado',
            'is_provisional' => false,
            'metadata' => ['origin' => 'operator'],
        ])->save();
        $remoteContact->forceFill([
            'metadata' => ['remote_hint' => true],
        ])->save();
        $otherRemoteIdentity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $remoteContact->id,
            'channel' => CommunicationChannel::WhatsApp,
            'address_encrypted' => '+559992032700',
            'address_hash' => hash('sha256', '+559992032700'),
            'address_masked' => '***2700',
            'is_active' => true,
        ]);
        [, $selfConversation] = $this->identityAndConversationForAddress(
            $tenant,
            $inbox,
            $sessionPn,
            'session',
        );
        $this->outboundMessage($tenant, $inbox, $lidIdentity, $lidConversation, '-lid');
        $this->outboundMessage($tenant, $inbox, $remoteIdentity, $remoteConversation, '-remote');
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $label = CommunicationLabel::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Alias LID',
            'color' => 'green',
        ]);
        DB::table('communication_conversation_clients')->insert([
            'tenant_id' => $tenant->id,
            'conversation_id' => $lidConversation->id,
            'client_id' => $client->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('communication_conversation_labels')->insert([
            'tenant_id' => $tenant->id,
            'conversation_id' => $lidConversation->id,
            'label_id' => $label->id,
            'assigned_by_membership_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = $this->event($inbox, GatewayEventType::MessageReceived, 'gateway-fragmented-0001', [
            'provider_message_id' => 'provider-fragmented-0001',
            'from' => $lid,
            'source_identity' => [
                'primary' => $lid,
                'primary_kind' => 'LID',
                'alternate' => $remotePn,
                'alternate_kind' => 'PN',
                'evidence' => 'MESSAGE_SOURCE_ALT',
            ],
            'direction' => 'INBOUND',
            'kind' => 'TEXT',
            'provider_type' => 'conversation',
            'family' => 'TEXT',
            'text' => 'Mensagem correlacionada',
        ]);

        $this->assertSame(
            3,
            CommunicationConversation::query()->withoutGlobalScopes()
                ->where('status', '<>', ConversationStatus::Resolved->value)
                ->count(),
        );
        $this->postSignedEvent($event)->assertNoContent();

        $activeConversation = CommunicationConversation::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('inbox_id', $inbox->id)
            ->where('status', '<>', ConversationStatus::Resolved->value)
            ->sole();
        $messageConversationIds = CommunicationMessage::query()->withoutGlobalScopes()
            ->whereIn('provider_message_id', [
                'provider-lid-0001',
                'provider-remote-0001',
                'provider-fragmented-0001',
            ])
            ->pluck('conversation_id')
            ->unique()
            ->values();

        $this->assertSame(
            $lidIdentity->refresh()->contact_id,
            $remoteIdentity->refresh()->contact_id,
        );
        $this->assertSame($lidContact->id, $lidIdentity->contact_id);
        $this->assertNull($remoteIdentity->canonical_identity_id);
        $this->assertSame($remoteIdentity->id, $lidIdentity->canonical_identity_id);
        $this->assertSame('Cliente curado', $lidContact->refresh()->name);
        $this->assertSame(
            ['origin' => 'operator', 'remote_hint' => true],
            $lidContact->metadata,
        );
        $remoteContact->refresh();
        $this->assertFalse($remoteContact->is_active);
        $this->assertSame($lidContact->id, $remoteContact->merged_into_contact_id);
        $this->assertNull($remoteContact->name);
        $this->assertNull($remoteContact->metadata);
        $this->assertSame($lidContact->id, $otherRemoteIdentity->refresh()->contact_id);
        $this->assertNull($otherRemoteIdentity->canonical_identity_id);
        $this->assertSame(0, $remoteContact->identities()->withoutGlobalScopes()->count());
        $this->assertSame([$activeConversation->id], $messageConversationIds->all());
        $this->assertSame(ConversationStatus::Resolved, $selfConversation->refresh()->status);
        $donor = collect([$lidConversation->refresh(), $remoteConversation->refresh()])
            ->firstWhere('id', '!=', $activeConversation->id);
        $this->assertSame($activeConversation->id, $donor?->merged_into_conversation_id);
        $this->assertDatabaseHas('communication_conversation_clients', [
            'conversation_id' => $activeConversation->id,
            'client_id' => $client->id,
        ]);
        $this->assertDatabaseHas('communication_conversation_labels', [
            'conversation_id' => $activeConversation->id,
            'label_id' => $label->id,
        ]);
    }

    public function test_history_retry_with_same_provider_message_id_still_correlates_lid_and_pn(): void
    {
        [$tenant, $inbox] = $this->context();
        $lid = 'lid:149865032093945';
        $remotePn = '+559992032709';
        $firstPayload = [
            'provider_message_id' => 'provider-retry-alias-0001',
            'from' => $lid,
            'source_identity' => [
                'primary' => $lid,
                'primary_kind' => 'LID',
                'evidence' => 'CHAT',
            ],
            'direction' => 'INBOUND',
            'kind' => 'TEXT',
            'provider_type' => 'conversation',
            'family' => 'TEXT',
            'text' => 'Mensagem sem alias',
        ];
        $this->postSignedEvent($this->event(
            $inbox,
            GatewayEventType::HistorySynced,
            'gateway-retry-lid-0001',
            [
                'batch_id' => 'history-retry-lid-batch-0001',
                'complete' => true,
                'messages' => [$firstPayload],
            ],
        ))->assertNoContent();
        $message = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('provider_message_id', 'provider-retry-alias-0001')
            ->sole();
        $originalConversationId = (int) $message->conversation_id;
        [, $pnConversation] = $this->identityAndConversationForAddress(
            $tenant,
            $inbox,
            $remotePn,
            'remote',
        );

        $retry = $this->event($inbox, GatewayEventType::MessageReceived, 'gateway-retry-alias-0002', [
            ...$firstPayload,
            'history' => true,
            'source_identity' => [
                'primary' => $lid,
                'primary_kind' => 'LID',
                'alternate' => $remotePn,
                'alternate_kind' => 'PN',
                'evidence' => 'MESSAGE_SOURCE_ALT',
            ],
        ]);
        $this->postSignedEvent($retry)
            ->assertNoContent()
            ->assertHeader('X-Communication-Result', 'processed');

        $message->refresh();
        $this->assertDatabaseCount('communication_messages', 1);
        $this->assertSame($pnConversation->id, $message->conversation_id);
        $this->assertSame($pnConversation->id, CommunicationEvent::query()
            ->withoutGlobalScopes()
            ->where('gateway_event_id', 'gateway-retry-alias-0002')
            ->value('conversation_id'));
        $historicalConversation = CommunicationConversation::query()
            ->withoutGlobalScopes()
            ->findOrFail($originalConversationId);
        $this->assertSame(ConversationStatus::Resolved, $historicalConversation->status);
        $this->assertNull($historicalConversation->merged_into_conversation_id);
    }

    public function test_flow_run_conversation_survives_alias_merge_and_conflicting_runs_fail_closed(): void
    {
        [$tenant, $inbox] = $this->context();
        $lid = 'lid:149865032093945';
        $remotePn = '+559992032709';
        [, $lidConversation] = $this->identityAndConversationForAddress($tenant, $inbox, $lid, 'lid');
        [, $pnConversation] = $this->identityAndConversationForAddress($tenant, $inbox, $remotePn, 'remote');
        [$flow, $version] = $this->flowVersion($tenant);
        $run = $this->flowRun($tenant, $flow, $version, $lidConversation);

        $event = $this->aliasEvent(
            $inbox,
            'gateway-flow-survivor-0001',
            'provider-flow-survivor-0001',
            $lid,
            $remotePn,
        );
        $this->postSignedEvent($event)->assertNoContent();

        $this->assertSame($lidConversation->id, $run->refresh()->conversation_id);
        $this->assertNull($lidConversation->refresh()->merged_into_conversation_id);
        $this->assertSame($lidConversation->id, $pnConversation->refresh()->merged_into_conversation_id);

        [$otherTenant, $otherInbox] = $this->context();
        [, $otherLidConversation] = $this->identityAndConversationForAddress(
            $otherTenant,
            $otherInbox,
            $lid,
            'lid',
        );
        [, $otherPnConversation] = $this->identityAndConversationForAddress(
            $otherTenant,
            $otherInbox,
            $remotePn,
            'remote',
        );
        [$otherFlow, $otherVersion] = $this->flowVersion($otherTenant);
        $this->flowRun($otherTenant, $otherFlow, $otherVersion, $otherLidConversation);
        $this->flowRun($otherTenant, $otherFlow, $otherVersion, $otherPnConversation);

        Log::spy();
        $this->postSignedEvent($this->aliasEvent(
            $otherInbox,
            'gateway-flow-conflict-0001',
            'provider-flow-conflict-0001',
            $lid,
            $remotePn,
        ))->assertConflict()->assertJson(['error' => 'PEER_CORRELATION_CONFLICT']);

        $this->assertSame(2, CommunicationConversation::query()->withoutGlobalScopes()
            ->where('tenant_id', $otherTenant->id)
            ->where('status', '<>', ConversationStatus::Resolved->value)
            ->count());
        $this->assertDatabaseMissing('communication_messages', [
            'tenant_id' => $otherTenant->id,
            'provider_message_id' => 'provider-flow-conflict-0001',
        ]);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'whatsapp_peer_correlation_flow_conflict',
                Mockery::on(static fn (array $context): bool => (
                    ($context['code'] ?? null) === 'PEER_CORRELATION_CONFLICT'
                    && (int) ($context['tenant_id'] ?? 0) === (int) $otherTenant->id
                    && (int) ($context['inbox_id'] ?? 0) === (int) $otherInbox->id
                    && count($context['conversation_ids'] ?? []) === 2
                )),
            );
    }

    public function test_message_timestamps_remain_monotonic_for_delayed_live_and_history(): void
    {
        [, $inbox] = $this->context();
        $address = '+5511999990042';
        $latestAt = '2026-07-28T15:00:00+00:00';
        $delayedAt = '2026-07-28T14:00:00+00:00';
        foreach ([
            ['gateway-time-latest-0001', 'provider-time-latest-0001', $latestAt],
            ['gateway-time-delayed-0001', 'provider-time-delayed-0001', $delayedAt],
        ] as [$eventId, $providerId, $occurredAt]) {
            $this->postSignedEvent($this->event($inbox, GatewayEventType::MessageReceived, $eventId, [
                'provider_message_id' => $providerId,
                'from' => $address,
                'occurred_at' => $occurredAt,
                'direction' => 'INBOUND',
                'kind' => 'TEXT',
                'provider_type' => 'conversation',
                'family' => 'TEXT',
                'text' => 'Mensagem temporal',
            ]))->assertNoContent();
        }
        $liveConversation = CommunicationConversation::query()->withoutGlobalScopes()
            ->whereHas('identity', fn ($query) => $query
                ->withoutGlobalScopes()
                ->where('address_hash', hash('sha256', $address)))
            ->sole();
        $this->assertSame($latestAt, $liveConversation->last_message_at?->toIso8601String());

        [, $historyInbox] = $this->context();
        $historyAt = '2025-03-10T09:30:00+00:00';
        $this->postSignedEvent($this->event(
            $historyInbox,
            GatewayEventType::HistorySynced,
            'gateway-history-time-0001',
            [
                'batch_id' => 'history-time-batch-0001',
                'complete' => true,
                'messages' => [[
                    'provider_message_id' => 'provider-history-time-0001',
                    'from' => '+5511999990043',
                    'occurred_at' => $historyAt,
                    'direction' => 'INBOUND',
                    'kind' => 'TEXT',
                    'provider_type' => 'conversation',
                    'family' => 'TEXT',
                    'text' => 'Mensagem histórica',
                ]],
            ],
        ))->assertNoContent();
        $historyMessage = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('provider_message_id', 'provider-history-time-0001')
            ->sole();
        $this->assertSame(
            $historyAt,
            CommunicationConversation::query()
                ->withoutGlobalScopes()
                ->findOrFail($historyMessage->conversation_id)
                ->last_message_at
                ?->toIso8601String(),
        );
        $this->assertFalse(
            CommunicationConversationUnreadMessage::query()
                ->withoutGlobalScopes()
                ->where('message_id', $historyMessage->id)
                ->exists(),
        );
    }

    public function test_older_phone_evidence_does_not_replace_newer_canonical_phone(): void
    {
        [$tenant, $inbox] = $this->context();
        $lid = 'lid:149865032093945';
        $olderPn = '+559992032701';
        $newerPn = '+559992032709';
        $newerAt = '2026-07-28T16:00:00+00:00';
        $olderAt = '2026-07-28T15:00:00+00:00';

        $newer = $this->aliasEvent(
            $inbox,
            'gateway-canonical-newer-0001',
            'provider-canonical-newer-0001',
            $lid,
            $newerPn,
        );
        $newer['payload']['occurred_at'] = $newerAt;
        $this->postSignedEvent($newer)->assertNoContent();

        $older = $this->aliasEvent(
            $inbox,
            'gateway-canonical-older-0001',
            'provider-canonical-older-0001',
            $lid,
            $olderPn,
        );
        $older['payload']['occurred_at'] = $olderAt;
        $older['payload']['history'] = true;
        $this->postSignedEvent($older)->assertNoContent();

        $newerIdentity = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('address_hash', hash('sha256', $newerPn))
            ->sole();
        $olderIdentity = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('address_hash', hash('sha256', $olderPn))
            ->sole();
        $lidIdentity = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('address_hash', hash('sha256', $lid))
            ->sole();
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('merged_into_conversation_id')
            ->sole();

        $this->assertNull($newerIdentity->canonical_identity_id);
        $this->assertSame($newerIdentity->id, $olderIdentity->canonical_identity_id);
        $this->assertSame($newerIdentity->id, $lidIdentity->canonical_identity_id);
        $this->assertSame($newerIdentity->id, $conversation->identity_id);
        $this->assertSame($newerAt, $newerIdentity->last_seen_at?->toIso8601String());
        $this->assertSame($olderAt, $olderIdentity->last_seen_at?->toIso8601String());
    }

    public function test_profile_follows_a_new_canonical_phone_without_a_donor_conversation(): void
    {
        [$tenant, $inbox] = $this->context();
        $lid = 'lid:149865032093946';
        $olderPn = '+559992032711';
        $newerPn = '+559992032712';

        $older = $this->aliasEvent(
            $inbox,
            'gateway-profile-root-older-0001',
            'provider-profile-root-older-0001',
            $lid,
            $olderPn,
        );
        $older['payload']['occurred_at'] = '2026-07-28T15:00:00+00:00';
        $this->postSignedEvent($older)->assertNoContent();

        $olderIdentity = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('address_hash', hash('sha256', $olderPn))
            ->sole();
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('inbox_id', $inbox->id)
            ->where('identity_id', $olderIdentity->id)
            ->sole();
        $profile->forceFill(['picture_id' => 'provider-profile-before-root-change'])->save();

        $newer = $this->aliasEvent(
            $inbox,
            'gateway-profile-root-newer-0001',
            'provider-profile-root-newer-0001',
            $lid,
            $newerPn,
        );
        $newer['payload']['occurred_at'] = '2026-07-28T16:00:00+00:00';
        $this->postSignedEvent($newer)->assertNoContent();

        $newerIdentity = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('address_hash', hash('sha256', $newerPn))
            ->sole();
        $this->assertNull($newerIdentity->canonical_identity_id);
        $this->assertSame($newerIdentity->id, $olderIdentity->refresh()->canonical_identity_id);
        $this->assertSame($newerIdentity->id, $profile->refresh()->identity_id);
        $this->assertSame('provider-profile-before-root-change', $profile->picture_id);
        $this->assertSame(
            1,
            CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('inbox_id', $inbox->id)
                ->count(),
        );
    }

    public function test_same_lid_and_pn_remain_isolated_between_tenants(): void
    {
        [$firstTenant, $firstInbox] = $this->context();
        [$secondTenant, $secondInbox] = $this->context();
        $remotePn = '+559992032709';
        $lid = 'lid:149865032093945';
        foreach ([
            [$firstInbox, '+559981769536', 'first'],
            [$secondInbox, '+559982222222', 'second'],
        ] as [$inbox, $sessionPn, $suffix]) {
            $inbox->forceFill([
                'address_encrypted' => $sessionPn,
                'address_hash' => hash('sha256', $sessionPn),
                'address_masked' => '***'.substr($sessionPn, -4),
            ])->save();
            $event = $this->event(
                $inbox,
                GatewayEventType::MessageReceived,
                'gateway-tenant-alias-'.$suffix,
                [
                    'provider_message_id' => 'provider-tenant-alias-'.$suffix,
                    'from' => $lid,
                    'source_identity' => [
                        'primary' => $lid,
                        'primary_kind' => 'LID',
                        'alternate' => $remotePn,
                        'alternate_kind' => 'PN',
                        'evidence' => 'MESSAGE_SOURCE_ALT',
                    ],
                    'direction' => 'INBOUND',
                    'kind' => 'TEXT',
                    'provider_type' => 'conversation',
                    'family' => 'TEXT',
                    'text' => 'Tenant '.$suffix,
                ],
            );
            $this->postSignedEvent($event)->assertNoContent();
        }

        $firstIdentity = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $firstTenant->id)
            ->where('address_hash', hash('sha256', $remotePn))
            ->sole();
        $secondIdentity = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $secondTenant->id)
            ->where('address_hash', hash('sha256', $remotePn))
            ->sole();

        $this->assertNotSame($firstIdentity->id, $secondIdentity->id);
        $this->assertNotSame($firstIdentity->contact_id, $secondIdentity->contact_id);
        $this->assertSame(
            1,
            CommunicationConversation::query()->withoutGlobalScopes()
                ->where('tenant_id', $firstTenant->id)
                ->count(),
        );
        $this->assertSame(
            1,
            CommunicationConversation::query()->withoutGlobalScopes()
                ->where('tenant_id', $secondTenant->id)
                ->count(),
        );
    }

    public function test_internal_event_authenticates_raw_body_before_schema_and_rejects_stale_or_replayed_evidence(): void
    {
        [, $inbox] = $this->context();
        $path = '/api/internal/v1/communication/gateway/events';
        $signer = app(HmacSigner::class);
        $malformedBody = '{"contract_version":';

        $this->call('POST', $path, content: $malformedBody)
            ->assertUnauthorized()
            ->assertJson(['error' => 'INVALID_INTERNAL_SIGNATURE']);

        $malformedHeaders = $signer->headers(
            'POST',
            $path,
            $malformedBody,
            nonce: 'nonce-malformed-event-0001',
        );
        $this->call(
            'POST',
            $path,
            server: $this->transformHeadersToServerVars($malformedHeaders),
            content: $malformedBody,
        )
            ->assertUnprocessable()
            ->assertJson(['error' => 'INVALID_EVENT']);

        $event = $this->event($inbox, GatewayEventType::GatewayAlert, 'gateway-boundary-event-0001', [
            'code' => 'GATEWAY_UNAVAILABLE',
            'severity' => 'WARNING',
            'retryable' => true,
        ]);
        $event['tenant_id'] = 999;
        $tenantBody = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $tenantHeaders = $signer->headers(
            'POST',
            $path,
            $tenantBody,
            nonce: 'nonce-tenant-event-0001',
        );
        $this->call(
            'POST',
            $path,
            server: $this->transformHeadersToServerVars($tenantHeaders),
            content: $tenantBody,
        )
            ->assertUnprocessable()
            ->assertJson(['error' => 'INVALID_EVENT']);
        $this->assertDatabaseMissing('communication_events', [
            'gateway_event_id' => 'gateway-boundary-event-0001',
        ]);

        unset($event['tenant_id']);
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $staleHeaders = $signer->headers(
            'POST',
            $path,
            $body,
            now()->subSeconds(301)->getTimestamp(),
            'nonce-stale-event-0001',
        );
        $this->call(
            'POST',
            $path,
            server: $this->transformHeadersToServerVars($staleHeaders),
            content: $body,
        )
            ->assertUnauthorized()
            ->assertJson(['error' => 'INVALID_INTERNAL_SIGNATURE']);

        $headers = $signer->headers(
            'POST',
            $path,
            $body,
            nonce: 'nonce-valid-event-0001',
        );
        $server = $this->transformHeadersToServerVars($headers);
        $this->call('POST', $path, server: $server, content: $body)
            ->assertNoContent()
            ->assertHeader('X-Communication-Result', 'processed');
        $this->call('POST', $path, server: $server, content: $body)
            ->assertUnauthorized()
            ->assertJson(['error' => 'INVALID_INTERNAL_SIGNATURE']);
        $this->assertDatabaseHas('communication_events', [
            'gateway_event_id' => 'gateway-boundary-event-0001',
            'tenant_id' => $inbox->tenant_id,
        ]);
    }

    public function test_live_outbound_from_device_creates_message_reopens_pending_and_deduplicates_provider_id(): void
    {
        [$tenant, $inbox] = $this->context();
        [, $conversation] = $this->identityAndConversation($tenant, $inbox, ConversationStatus::Pending);

        $event = $this->event($inbox, GatewayEventType::MessageReceived, 'gateway-device-outbound-0001', [
            'provider_message_id' => 'provider-device-outbound-0001',
            'from' => '+5511999990002',
            'direction' => 'OUTBOUND',
            'kind' => 'TEXT',
            'provider_type' => 'conversation',
            'family' => 'TEXT',
            'text' => 'Enviado no celular',
        ]);

        $this->postSignedEvent($event)->assertNoContent()->assertHeader('X-Communication-Result', 'processed');
        $this->postSignedEvent($event)->assertNoContent()->assertHeader('X-Communication-Result', 'duplicate');

        $message = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('provider_message_id', 'provider-device-outbound-0001')
            ->firstOrFail();
        $this->assertSame(MessageDirection::Outbound, $message->direction);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame(MessageSource::Gateway, $message->source);
        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame(ConversationStatus::Open, $conversation->refresh()->status);
        $this->assertSame(1, CommunicationMessage::query()->withoutGlobalScopes()->count());
    }

    public function test_inbound_reopens_pending_conversation_and_receipts_never_regress_message_or_dispatch(): void
    {
        [$tenant, $inbox] = $this->context();
        [$identity, $conversation] = $this->identityAndConversation($tenant, $inbox, ConversationStatus::Pending);

        $this->postSignedEvent($this->event($inbox, GatewayEventType::MessageReceived, 'gateway-reply-0001', [
            'provider_message_id' => 'provider-reply-0001',
            'from' => '+5511999990002',
            'kind' => 'TEXT',
            'provider_type' => 'conversation',
            'family' => 'TEXT',
            'text' => 'Recebi, obrigado.',
        ]))->assertNoContent();

        $this->assertSame($conversation->id, CommunicationMessage::query()->withoutGlobalScopes()->firstOrFail()->conversation_id);
        $this->assertSame(ConversationStatus::Open, $conversation->refresh()->status);
        $this->assertNull($conversation->assignee_membership_id);

        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $outbound = CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $identity->id,
            'direction' => MessageDirection::Outbound,
            'kind' => MessageKind::Text,
            'source' => MessageSource::FiscalAutomation,
            'status' => MessageStatus::Accepted,
            'body_encrypted' => 'Mensagem fiscal',
            'provider_message_id' => 'provider-outbound-0001',
            'content_digest' => hash('sha256', 'Mensagem fiscal'),
            'occurred_at' => now(),
        ]);
        $dispatch = ClientCommunicationDispatch::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'conversation_id' => $conversation->id,
            'message_id' => $outbound->id,
            'module_key' => 'simples_mei',
            'submodule_key' => 'pgdasd',
            'period_key' => '2026-06',
            'channel' => CommunicationChannel::WhatsApp,
            'execution_mode' => CommunicationExecutionMode::WhatsAppNative,
            'status' => CommunicationDispatchStatus::Accepted,
            'recipient_masked' => '***0002',
            'recipient_hash' => hash('sha256', '+5511999990002'),
            'idempotency_key' => hash('sha256', 'receipt-test'),
        ]);
        $outbound->forceFill(['client_communication_dispatch_id' => $dispatch->id])->save();

        $this->postSignedEvent($this->event($inbox, GatewayEventType::MessageStatusChanged, 'gateway-receipt-played-0001', [
            'provider_message_id' => 'provider-outbound-0001',
            'status' => 'PLAYED',
        ]))->assertNoContent();
        $this->postSignedEvent($this->event($inbox, GatewayEventType::MessageStatusChanged, 'gateway-receipt-retry-0001', [
            'provider_message_id' => 'provider-outbound-0001',
            'status' => 'UNKNOWN',
            'error_code' => 'WHATSAPP_RECEIPT_RETRY',
        ]))->assertNoContent();
        $this->postSignedEvent($this->event($inbox, GatewayEventType::MessageStatusChanged, 'gateway-receipt-read-0001', [
            'provider_message_id' => 'provider-outbound-0001',
            'status' => 'READ',
        ]))->assertNoContent();
        $this->postSignedEvent($this->event($inbox, GatewayEventType::MessageStatusChanged, 'gateway-receipt-sent-0001', [
            'provider_message_id' => 'provider-outbound-0001',
            'status' => 'SENT',
        ]))->assertNoContent();

        $this->assertSame(MessageStatus::Played, $outbound->refresh()->status);
        $this->assertNotNull($outbound->played_at);
        $this->assertSame(CommunicationDispatchStatus::Read, $dispatch->refresh()->status);
        $this->assertNotNull($dispatch->read_at);
        $this->assertDatabaseCount('client_communication_events', 1);
        $projected = ClientCommunicationEvent::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('WAZYNC', $projected->source);
        $this->assertSame('gateway-receipt-played-0001', $projected->provider_event_id);
        $this->assertDatabaseHas('communication_events', [
            'gateway_event_id' => 'gateway-receipt-played-0001',
        ]);
        foreach ([
            'gateway-reply-0001',
            'gateway-receipt-played-0001',
            'gateway-receipt-retry-0001',
            'gateway-receipt-read-0001',
            'gateway-receipt-sent-0001',
        ] as $gatewayEventId) {
            $this->assertSame(1, CommunicationEvent::query()->withoutGlobalScopes()
                ->where('gateway_event_id', $gatewayEventId)->count());
        }
        // O inbound live também publica a projeção durável do ledger compartilhado
        // e a indisponibilidade sanitizada da foto inicial.
        $this->assertSame(7, CommunicationEvent::query()->withoutGlobalScopes()->count());
        $this->assertUnavailableProfilePictureEvent($inbox, $identity);
    }

    public function test_outbox_accepts_retries_and_terminally_classifies_failures(): void
    {
        [$tenant, $inbox] = $this->context();
        [$identity, $conversation] = $this->identityAndConversation($tenant, $inbox);
        $acceptedMessage = $this->outboundMessage($tenant, $inbox, $identity, $conversation, 'accepted');
        $accepted = $this->outbox($tenant, $inbox, $acceptedMessage, 'command-accepted-0001');
        $dispatcher = app(OutboxDispatcher::class);

        $dispatcher->dispatch((int) $accepted->id);
        $this->assertSame(OutboxStatus::Accepted, $accepted->refresh()->status);
        $this->assertSame(MessageStatus::Accepted, $acceptedMessage->refresh()->status);

        $retryMessage = $this->outboundMessage($tenant, $inbox, $identity, $conversation, 'retry');
        $retry = $this->outbox($tenant, $inbox, $retryMessage, 'command-retry-0001');
        $this->transport->failures['command-retry-0001'] = new CommunicationTransportException('GATEWAY_TEMPORARY', true);
        $dispatcher->dispatch((int) $retry->id);
        $this->assertSame(OutboxStatus::Retry, $retry->refresh()->status);
        $this->assertSame(1, $retry->attempt_count);
        $this->assertSame(MessageStatus::Queued, $retryMessage->refresh()->status);

        $retry->forceFill(['attempt_count' => 9, 'available_at' => now()->subSecond()])->save();
        $dispatcher->dispatch((int) $retry->id);
        $this->assertSame(OutboxStatus::Dead, $retry->refresh()->status);
        $this->assertSame(MessageStatus::Unknown, $retryMessage->refresh()->status);

        $failedMessage = $this->outboundMessage($tenant, $inbox, $identity, $conversation, 'failed');
        $failed = $this->outbox($tenant, $inbox, $failedMessage, 'command-failed-0001');
        $this->transport->failures['command-failed-0001'] = new CommunicationTransportException('INVALID_DESTINATION', false);
        $dispatcher->dispatch((int) $failed->id);
        $this->assertSame(OutboxStatus::Dead, $failed->refresh()->status);
        $this->assertSame(MessageStatus::Failed, $failedMessage->refresh()->status);
    }

    public function test_accepted_human_outbound_persists_read_receipt_follow_up_atomically(): void
    {
        Queue::fake();
        [$tenant, $inbox] = $this->context();
        [$identity, $conversation] = $this->identityAndConversation($tenant, $inbox);
        $inbound = CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $identity->id,
            'direction' => MessageDirection::Inbound,
            'kind' => MessageKind::Text,
            'source' => MessageSource::Gateway,
            'status' => MessageStatus::Delivered,
            'body_encrypted' => 'Mensagem exibida',
            'provider_message_id' => 'provider-inbound-receipt-0001',
            'content_digest' => hash('sha256', 'Mensagem exibida'),
            'occurred_at' => now()->subSecond(),
        ]);
        $outbound = $this->outboundMessage($tenant, $inbox, $identity, $conversation, 'receipt-follow-up');
        $outbound->forceFill(['metadata' => ['receipt_message_id' => $inbound->id]])->save();
        $send = $this->outbox($tenant, $inbox, $outbound, 'command-receipt-follow-up-0001');

        $dispatcher = app(OutboxDispatcher::class);
        $dispatcher->dispatch((int) $send->id);

        $this->assertSame(OutboxStatus::Accepted, $send->refresh()->status);
        $followUps = CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->where('effect_key', 'outbound-read-receipt:'.$send->id.':'.$inbound->id)
            ->get();
        $this->assertCount(1, $followUps, (string) CommunicationEvent::query()
            ->withoutGlobalScopes()
            ->where('type', 'outbound.read_receipt.release_failed')
            ->get(['payload'])
            ->toJson());
        $followUp = $followUps->firstOrFail();
        $this->assertSame(GatewayCommandType::MarkMessage, $followUp->type);
        $this->assertSame(OutboxStatus::Pending, $followUp->status);
        $this->assertSame($outbound->id, $followUp->message_id);
        $this->assertSame(
            ['provider-inbound-receipt-0001'],
            $followUp->payload_encrypted['message_ids'] ?? null,
        );

        $dispatcher->dispatch((int) $send->id);
        $this->assertSame(
            1,
            CommunicationOutboxEntry::query()->withoutGlobalScopes()
                ->where('effect_key', 'outbound-read-receipt:'.$send->id.':'.$inbound->id)
                ->count(),
        );
    }

    public function test_media_retry_rehydrates_original_attachment_and_schedules_durable_cleanup(): void
    {
        Queue::fake();
        [$tenant, $inbox] = $this->context();
        [$identity, $conversation] = $this->identityAndConversation($tenant, $inbox);
        $message = $this->outboundMessage($tenant, $inbox, $identity, $conversation, 'media-retry');
        $oldContext = [
            'tenant_id' => (int) $tenant->id,
            'inbox_id' => (int) $inbox->id,
            'gateway_event_id' => 'gateway-old-media-0001',
            'sha256' => hash('sha256', 'old'),
        ];
        $old = app(MediaStore::class)->putStream(Utils::streamFor('old'), $oldContext);
        $attachment = CommunicationAttachment::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'message_id' => $message->id,
            'object_id' => $old['object_id'],
            'original_name_encrypted' => 'old.png',
            'mime_type' => 'image/png',
            'size_bytes' => $old['size_bytes'],
            'sha256' => $old['sha256'],
            'storage_context' => $oldContext,
        ]);
        $bytes = 'new-image';
        $this->transport->media['spool-media-retry-0001'] = $bytes;
        $event = $this->event($inbox, GatewayEventType::MediaRetryUpdated, 'gateway-media-retry-0001', [
            'provider_message_id' => $message->provider_message_id,
            'status' => 'READY',
            'generation' => 2,
            'attempt' => 3,
            'spool_id' => 'spool-media-retry-0001',
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'mime_type' => 'image/png',
            'filename' => 'nova.png',
        ]);

        $this->postSignedEvent($event)->assertNoContent();
        $this->postSignedEvent($event)->assertNoContent()->assertHeader('X-Communication-Result', 'duplicate');

        $attachment->refresh();
        $this->assertSame(hash('sha256', $bytes), $attachment->sha256);
        $this->assertSame('READY', $message->refresh()->metadata['media_state']);
        $this->assertDatabaseCount('communication_messages', 1);
        $this->assertDatabaseCount('communication_attachments', 1);
        $this->assertTrue(app(MediaStore::class)->exists($old['object_id']));
        Queue::assertPushed(DeleteMediaObjectJob::class, fn ($job) => $job->objectId === $old['object_id']);

        (new DeleteMediaObjectJob($old['object_id']))->handle(app(MediaStore::class));
        $this->assertFalse(app(MediaStore::class)->exists($old['object_id']));
    }

    public function test_logout_outbox_remains_dispatchable_after_inbox_is_soft_deleted(): void
    {
        [$tenant, $inbox] = $this->context();
        $entry = CommunicationOutboxEntry::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'command_id' => 'command-logout-deleted-0001',
            'session_id' => $inbox->session_id,
            'type' => GatewayCommandType::LogoutSession,
            'payload_encrypted' => [],
            'payload_digest' => hash('sha256', '{}'),
            'status' => OutboxStatus::Pending,
            'available_at' => now()->subSecond(),
        ]);
        $inbox->forceFill(['is_enabled' => false, 'is_default' => false])->save();
        $entry->forceFill(['inbox_id' => null])->save();
        $inbox->delete();

        app(OutboxDispatcher::class)->dispatch((int) $entry->id);

        $this->assertSame(OutboxStatus::Accepted, $entry->refresh()->status);
        $this->assertNull($entry->inbox_id);
    }

    public function test_late_event_for_deleted_inbox_is_ignored_without_reopening_operation(): void
    {
        [, $inbox] = $this->context();
        $sessionId = (string) $inbox->session_id;
        $inbox->forceFill(['is_enabled' => false, 'is_default' => false])->save();
        $inbox->delete();
        $event = [
            'contract_version' => 'v1',
            'gateway_event_id' => 'gateway-archived-0001',
            'session_id' => $sessionId,
            'type' => GatewayEventType::MessageReceived->value,
            'occurred_at' => now()->toIso8601String(),
            'payload' => [
                'provider_message_id' => 'provider-archived-0001',
                'from' => '+5511999990003',
                'kind' => 'TEXT',
                'provider_type' => 'conversation',
                'family' => 'TEXT',
                'text' => 'Evento tardio',
            ],
        ];

        $this->postSignedEvent($event)
            ->assertNoContent()
            ->assertHeader('X-Communication-Result', 'ignored');

        $this->assertDatabaseCount('communication_messages', 0);
        $this->assertDatabaseCount('communication_events', 0);
    }

    /** @return array{Tenant,CommunicationInbox} */
    private function context(): array
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Atendimento',
            'session_id' => 'session-'.Str::ulid(),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
            'is_default' => true,
        ]);

        return [$tenant, $inbox];
    }

    /** @return array{CommunicationIdentity,CommunicationConversation} */
    private function identityAndConversation(
        Tenant $tenant,
        CommunicationInbox $inbox,
        ConversationStatus $status = ConversationStatus::Open,
    ): array {
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente',
            'is_active' => true,
        ]);
        $address = '+5511999990002';
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::WhatsApp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => '***0002',
            'is_active' => true,
        ]);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => $status,
            'last_message_at' => now(),
        ]);

        return [$identity, $conversation];
    }

    /** @return array{CommunicationIdentity,CommunicationConversation} */
    private function identityAndConversationForAddress(
        Tenant $tenant,
        CommunicationInbox $inbox,
        string $address,
        string $suffix,
    ): array {
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => null,
            'is_provisional' => true,
            'is_active' => true,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::WhatsApp,
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
            'last_message_at' => now()->addSeconds(match ($suffix) {
                'lid' => 1,
                'remote' => 2,
                default => 3,
            }),
        ]);

        return [$identity, $conversation];
    }

    private function outboundMessage(
        Tenant $tenant,
        CommunicationInbox $inbox,
        CommunicationIdentity $identity,
        CommunicationConversation $conversation,
        string $suffix,
    ): CommunicationMessage {
        return CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $identity->id,
            'direction' => MessageDirection::Outbound,
            'kind' => MessageKind::Text,
            'source' => MessageSource::Human,
            'status' => MessageStatus::Queued,
            'body_encrypted' => 'Mensagem '.$suffix,
            'provider_message_id' => 'provider-'.$suffix.'-0001',
            'content_digest' => hash('sha256', $suffix),
            'occurred_at' => now(),
        ]);
    }

    private function outbox(
        Tenant $tenant,
        CommunicationInbox $inbox,
        CommunicationMessage $message,
        string $commandId,
    ): CommunicationOutboxEntry {
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->findOrFail($message->identity_id);
        $payload = [
            'to' => (string) $identity->address_encrypted,
            'kind' => 'TEXT',
            'text' => $message->body_encrypted,
        ];

        return CommunicationOutboxEntry::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'message_id' => $message->id,
            'command_id' => $commandId,
            'session_id' => $inbox->session_id,
            'type' => GatewayCommandType::SendMessage,
            'payload_encrypted' => $payload,
            'payload_digest' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'status' => OutboxStatus::Pending,
            'available_at' => now()->subSecond(),
        ]);
    }

    /** @return array{CommunicationFlow,CommunicationFlowVersion} */
    private function flowVersion(Tenant $tenant): array
    {
        $flow = CommunicationFlow::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Fluxo '.Str::ulid(),
            'status' => FlowStatus::Active,
            'lock_version' => 1,
        ]);
        $graph = [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'data' => []],
                ['id' => 'end', 'type' => 'end', 'data' => []],
            ],
            'edges' => [
                ['id' => 'edge', 'source' => 'start', 'target' => 'end'],
            ],
        ];
        $version = CommunicationFlowVersion::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'flow_id' => $flow->id,
            'version' => 1,
            'graph_encrypted' => $graph,
            'graph_digest' => hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR)),
            'published_at' => now(),
        ]);

        return [$flow, $version];
    }

    private function flowRun(
        Tenant $tenant,
        CommunicationFlow $flow,
        CommunicationFlowVersion $version,
        CommunicationConversation $conversation,
    ): CommunicationFlowRun {
        return CommunicationFlowRun::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'conversation_id' => $conversation->id,
            'status' => FlowRunStatus::WaitingInput,
            'current_node_id' => 'start',
            'context_encrypted' => [],
            'started_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function aliasEvent(
        CommunicationInbox $inbox,
        string $gatewayEventId,
        string $providerMessageId,
        string $lid,
        string $remotePn,
    ): array {
        return $this->event($inbox, GatewayEventType::MessageReceived, $gatewayEventId, [
            'provider_message_id' => $providerMessageId,
            'from' => $lid,
            'source_identity' => [
                'primary' => $lid,
                'primary_kind' => 'LID',
                'alternate' => $remotePn,
                'alternate_kind' => 'PN',
                'evidence' => 'MESSAGE_SOURCE_ALT',
            ],
            'direction' => 'INBOUND',
            'kind' => 'TEXT',
            'provider_type' => 'conversation',
            'family' => 'TEXT',
            'text' => 'Mensagem correlacionada',
        ]);
    }

    /** @param array<string,mixed> $payload */
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
        $headers = app(HmacSigner::class)->headers('POST', $path, $body);

        return $this->json('POST', $path, $event, $headers, JSON_UNESCAPED_SLASHES);
    }

    private function assertUnavailableProfilePictureEvent(
        CommunicationInbox $inbox,
        CommunicationIdentity $identity,
    ): void {
        $event = CommunicationEvent::query()->withoutGlobalScopes()
            ->where('type', 'contact.profile_picture.updated')
            ->where('inbox_id', $inbox->id)
            ->sole();

        $this->assertNull($event->gateway_event_id);
        $this->assertNull($event->conversation_id);
        $this->assertNull($event->message_id);
        $expectedPayload = [
            'inbox_id' => (int) $inbox->id,
            'identity_id' => (int) $identity->id,
            'state' => ProfilePictureState::Unavailable->value,
            'version' => 1,
        ];
        $payload = $event->payload;
        ksort($expectedPayload);
        ksort($payload);

        $this->assertSame($expectedPayload, $payload);
    }
}

final class FakeCommunicationTransport implements CommunicationTransport
{
    /** @var array<string,string> */
    public array $media = [];

    /** @var array<string,CommunicationTransportException> */
    public array $failures = [];

    public int $downloadCalls = 0;

    public function dispatch(GatewayCommandData $command): GatewayCommandReceipt
    {
        if (isset($this->failures[$command->commandId])) {
            throw $this->failures[$command->commandId];
        }

        return new GatewayCommandReceipt($command->commandId, false);
    }

    public function query(GatewayQueryData $query): array
    {
        return [
            'query_id' => $query->queryId,
            'type' => $query->type->value,
            'result' => [],
        ];
    }

    public function sessionStatus(string $sessionId): array
    {
        return [
            'session_id' => $sessionId,
            'status' => 'CONNECTED',
            'desired_connected' => true,
            'reconnect_count' => 0,
            'connected' => true,
            'logged_in' => true,
            'ready' => true,
            'has_credentials' => true,
        ];
    }

    public function downloadMedia(string $spoolId): StreamInterface
    {
        $this->downloadCalls++;

        return Utils::streamFor($this->media[$spoolId] ?? '');
    }
}
