<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\CommunicationChannel;
use App\Enums\TenantRole;
use App\Http\Resources\Communication\MessageResource;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxMember;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Communication\Media\MediaStore;
use App\Support\CurrentTenant;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CommunicationSharedContentAndInitiationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake();
        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
            'communication.media.disk_root' => sys_get_temp_dir().'/communication-shared-content-tests-'.Str::ulid(),
            'communication.outbound_conversation.enabled' => true,
            'communication.outbound_conversation.kill_switch' => false,
            'communication.outbound_conversation.allow_all_tenants' => true,
            'communication.outbound_conversation.allowed_tenant_ids' => [],
        ]);
    }

    public function test_shared_content_is_nested_private_paginated_and_inbox_scoped(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $visible = $this->inbox($tenant, 'Visível', '+5511000000001');
        $hidden = $this->inbox($tenant, 'Oculta', '+5511000000002');
        $this->member($visible, $operator);
        [$contact, $identity] = $this->contact($tenant, '+5511999990001');
        $conversation = $this->conversation($tenant, $visible, $identity);
        $hiddenConversation = $this->conversation($tenant, $hidden, $identity);

        $imageMessage = $this->message($tenant, $visible, $conversation, MessageKind::Image);
        $image = $this->attachment($tenant, $imageMessage, 'foto.jpg', 'image/jpeg');
        $secondMessage = $this->message($tenant, $visible, $conversation, MessageKind::Video);
        $second = $this->attachment($tenant, $secondMessage, 'video.mp4', 'video/mp4');
        $linkMessage = $this->message($tenant, $visible, $conversation, MessageKind::Text);
        $linkMessage->forceFill([
            'content_encrypted' => [
                'link_preview' => [
                    'url' => 'https://example.com/documento',
                    'title' => 'Documento',
                ],
            ],
        ])->save();
        $hiddenMessage = $this->message($tenant, $hidden, $hiddenConversation, MessageKind::Document);
        $this->attachment($tenant, $hiddenMessage, 'secreto.pdf', 'application/pdf');

        $this->authenticate($operator);

        $first = $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/shared-content?category=media&limit=1')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('data.0.type', 'attachment')
            ->assertJsonPath('data.0.attachment.id', $second->id)
            ->assertJsonPath('data.0.attachment.mime_type', 'video/mp4');
        $this->assertNotNull($first->json('meta.next_cursor'));
        $this->assertArrayNotHasKey('object_id', $first->json('data.0.attachment'));
        $this->assertArrayNotHasKey('storage_context', $first->json('data.0.attachment'));

        $this->attachment($tenant, $imageMessage, 'tardio.jpg', 'image/jpeg');
        $secondPage = $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/shared-content?category=media&limit=1&cursor='.urlencode((string) $first->json('meta.next_cursor')))
            ->assertOk()
            ->assertJsonPath('data.0.attachment.id', $image->id);
        $this->assertNull($secondPage->json('meta.next_cursor'));

        $invalidCursor = $this->mutateCursor((string) $first->json('meta.next_cursor'), [
            'occurred_at' => 'timestamp-invalido',
        ]);
        $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/shared-content?category=media&cursor='.urlencode($invalidCursor))
            ->assertUnprocessable();
        $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/shared-content?category=documents&cursor='.urlencode((string) $first->json('meta.next_cursor')))
            ->assertUnprocessable();
        $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/shared-content?category=media&inbox_id='.$visible->id)
            ->assertUnprocessable();

        $this->getJson('/api/v1/communication/contacts/'.$contact->id.'/shared-content?category=links')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('data.0.link.url', 'https://example.com/documento');
        $this->getJson('/api/v1/communication/contacts/'.$contact->id.'/shared-content?category=media&cursor='.urlencode((string) $first->json('meta.next_cursor')))
            ->assertUnprocessable();
        $documents = $this->getJson('/api/v1/communication/contacts/'.$contact->id.'/shared-content?category=documents')
            ->assertOk();
        $this->assertSame([], $documents->json('data'));
        $this->getJson('/api/v1/communication/conversations/'.$hiddenConversation->id.'/shared-content?category=documents')
            ->assertNotFound();
    }

    public function test_contact_shared_content_includes_nested_donors_and_merged_conversations(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Histórico', '+5511000000010');
        $this->member($inbox, $operator);
        [$canonical, $canonicalIdentity] = $this->contact($tenant, '+5511999990010');
        [$donor, $donorIdentity] = $this->contact($tenant, '+5511999990011');
        [$nestedDonor, $nestedIdentity] = $this->contact($tenant, '+5511999990012');
        $donor->forceFill(['is_active' => false, 'merged_into_contact_id' => $canonical->id])->save();
        $nestedDonor->forceFill(['is_active' => false, 'merged_into_contact_id' => $donor->id])->save();
        $survivor = $this->conversation($tenant, $inbox, $canonicalIdentity);
        $donorConversation = $this->conversation($tenant, $inbox, $donorIdentity, ConversationStatus::Resolved);
        $nestedConversation = $this->conversation($tenant, $inbox, $nestedIdentity, ConversationStatus::Resolved);
        $donorConversation->forceFill(['merged_into_conversation_id' => $survivor->id])->save();
        $nestedConversation->forceFill(['merged_into_conversation_id' => $survivor->id])->save();
        $donorAttachment = $this->attachment(
            $tenant,
            $this->message($tenant, $inbox, $donorConversation, MessageKind::Document),
            'historico.pdf',
            'application/pdf',
        );
        $nestedAttachment = $this->attachment(
            $tenant,
            $this->message($tenant, $inbox, $nestedConversation, MessageKind::Document),
            'historico-antigo.zip',
            'application/zip',
        );

        $this->authenticate($operator);
        $response = $this->getJson('/api/v1/communication/contacts/'.$canonical->id.'/shared-content?category=documents')
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$donorAttachment->id, $nestedAttachment->id],
            array_map(
                static fn (array $item): int => (int) data_get($item, 'attachment.id'),
                $response->json('data'),
            ),
        );
    }

    public function test_view_once_is_absent_and_cannot_be_streamed_even_when_blob_exists(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Visível', '+5511000000003');
        $this->member($inbox, $operator);
        [, $identity] = $this->contact($tenant, '+5511999990002');
        $conversation = $this->conversation($tenant, $inbox, $identity);
        $message = $this->message($tenant, $inbox, $conversation, MessageKind::Image);
        $message->forceFill(['metadata' => ['view_once' => true]])->save();
        $metadata = [
            'tenant_id' => (int) $tenant->id,
            'inbox_id' => (int) $inbox->id,
            'gateway_event_id' => 'view-once-test',
            'sha256' => hash('sha256', 'privado'),
        ];
        $stored = app(MediaStore::class)->putStream(Utils::streamFor('privado'), $metadata);
        $attachment = CommunicationAttachment::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'message_id' => $message->id,
            'object_id' => $stored['object_id'],
            'original_name_encrypted' => 'view-once.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => $stored['size_bytes'],
            'sha256' => $stored['sha256'],
            'storage_context' => $metadata,
        ]);
        $staleMessage = $this->message($tenant, $inbox, $conversation, MessageKind::Image);
        $staleMessage->forceFill(['metadata' => ['view_once' => 'unrecognized-value']])->save();
        $staleAttachment = $this->attachment($tenant, $staleMessage, 'stale.jpg', 'image/jpeg');
        $staleLinkMessage = $this->message($tenant, $inbox, $conversation, MessageKind::Text);
        $staleLinkMessage->forceFill([
            'metadata' => ['view_once' => 'unrecognized-value'],
            'content_encrypted' => [
                'link_preview' => [
                    'url' => 'https://example.test/stale',
                    'title' => 'Link inválido',
                ],
            ],
        ])->save();
        $explicitFalseMessage = $this->message($tenant, $inbox, $conversation, MessageKind::Image);
        $explicitFalseMessage->forceFill(['metadata' => ['view_once' => false]])->save();
        $explicitFalseAttachment = $this->attachment(
            $tenant,
            $explicitFalseMessage,
            'regular.jpg',
            'image/jpeg',
        );
        $explicitFalseLink = $this->message($tenant, $inbox, $conversation, MessageKind::Text);
        $explicitFalseLink->forceFill([
            'metadata' => ['view_once' => false],
            'content_encrypted' => [
                'link_preview' => [
                    'url' => 'https://example.test/regular',
                    'title' => 'Link regular',
                ],
            ],
        ])->save();

        $this->authenticate($operator);
        $media = $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/shared-content?category=media')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertSame($explicitFalseAttachment->id, $media->json('data.0.attachment.id'));
        $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/shared-content?category=links')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message_id', $explicitFalseLink->id);
        $this->get('/api/v1/communication/attachments/'.$attachment->id.'/download')
            ->assertNotFound();
        $message->forceFill([
            'metadata' => ['view_once' => false],
            'quarantined_at' => now(),
        ])->save();
        $this->get('/api/v1/communication/attachments/'.$attachment->id.'/download')
            ->assertNotFound();
        $this->get('/api/v1/communication/attachments/'.$attachment->id.'/preview')
            ->assertNotFound();

        $staleResource = (new MessageResource(
            $staleMessage->load('attachments'),
        ))->resolve();
        $this->assertSame('UNAVAILABLE', $staleResource['availability']['state']);
        $this->assertNull($staleResource['body']);
        $this->assertNull($staleResource['content']);
        $this->assertSame([], $staleResource['attachments']);
    }

    public function test_shared_content_excludes_purge_revoke_plain_url_and_cross_tenant(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $foreignOperator = User::factory()->forTenant($foreignTenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Filtros', '+5511000000020');
        $foreignInbox = $this->inbox($foreignTenant, 'Estrangeira', '+5511000000021');
        $this->member($inbox, $operator);
        $this->member($foreignInbox, $foreignOperator);
        [$contact, $identity] = $this->contact($tenant, '+5511999990020');
        [, $foreignIdentity] = $this->contact($foreignTenant, '+5511999990021');
        $conversation = $this->conversation($tenant, $inbox, $identity);
        $foreignConversation = $this->conversation($foreignTenant, $foreignInbox, $foreignIdentity);

        $audioMessage = $this->message($tenant, $inbox, $conversation, MessageKind::Audio);
        $audio = $this->attachment($tenant, $audioMessage, 'voz.ogg', 'audio/ogg');
        $stickerMessage = $this->message($tenant, $inbox, $conversation, MessageKind::Sticker);
        $sticker = $this->attachment($tenant, $stickerMessage, 'sticker.webp', 'image/webp');
        $purgedMessage = $this->message($tenant, $inbox, $conversation, MessageKind::Image);
        $purgedMessage->forceFill(['purged_at' => now()])->save();
        $this->attachment($tenant, $purgedMessage, 'purged.jpg', 'image/jpeg');
        $revokedMessage = $this->message($tenant, $inbox, $conversation, MessageKind::Document);
        $revokedMessage->forceFill(['revoked_at' => now()])->save();
        $this->attachment($tenant, $revokedMessage, 'revoked.pdf', 'application/pdf');
        $purgedAttachmentMessage = $this->message($tenant, $inbox, $conversation, MessageKind::Document);
        $purgedAttachment = $this->attachment($tenant, $purgedAttachmentMessage, 'anexo-purged.pdf', 'application/pdf');
        $purgedAttachment->forceFill(['purged_at' => now()])->save();
        $plainUrl = $this->message($tenant, $inbox, $conversation, MessageKind::Text);
        $plainUrl->forceFill(['body_encrypted' => 'Veja https://example.com/sem-preview'])->save();
        $documentMessage = $this->message($tenant, $inbox, $conversation, MessageKind::Document);
        $document = $this->attachment($tenant, $documentMessage, 'ok.pdf', 'application/pdf');

        $this->authenticate($operator);
        $media = $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/shared-content?category=media')
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$audio->id, $sticker->id],
            array_map(
                static fn (array $item): int => (int) data_get($item, 'attachment.id'),
                $media->json('data'),
            ),
        );
        $this->assertArrayNotHasKey('object_id', $media->json('data.0.attachment'));
        $this->assertArrayNotHasKey('sha256', $media->json('data.0.attachment'));
        $this->assertArrayNotHasKey('storage_context', $media->json('data.0.attachment'));

        $documents = $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/shared-content?category=documents')
            ->assertOk();
        $this->assertSame([$document->id], array_map(
            static fn (array $item): int => (int) data_get($item, 'attachment.id'),
            $documents->json('data'),
        ));
        $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/shared-content?category=links')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/communication/contacts/'.$contact->id.'/shared-content?category=media')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->authenticate($foreignOperator);
        $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/shared-content?category=media')
            ->assertNotFound();
        $this->getJson('/api/v1/communication/contacts/'.$contact->id.'/shared-content?category=documents')
            ->assertNotFound();
        $this->getJson('/api/v1/communication/conversations/'.$foreignConversation->id.'/shared-content?category=media')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_message_anchor_keeps_message_in_page_and_rejects_foreign_anchor(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Timeline', '+5511000000004');
        $this->member($inbox, $operator);
        [, $identity] = $this->contact($tenant, '+5511999990003');
        $conversation = $this->conversation($tenant, $inbox, $identity);
        $other = $this->conversation($tenant, $inbox, $identity, ConversationStatus::Resolved);
        $base = now()->startOfSecond();
        $first = $this->message($tenant, $inbox, $conversation, MessageKind::Text);
        $anchor = $this->message($tenant, $inbox, $conversation, MessageKind::Text);
        $third = $this->message($tenant, $inbox, $conversation, MessageKind::Text);
        $first->forceFill(['occurred_at' => $base->copy()->addMicroseconds(100)])->save();
        $anchor->forceFill(['occurred_at' => $base->copy()->addMicroseconds(200)])->save();
        $third->forceFill(['occurred_at' => $base->copy()->addMicroseconds(300)])->save();
        $foreign = $this->message($tenant, $inbox, $other, MessageKind::Text);

        $this->authenticate($operator);
        $anchored = $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/messages?anchor=message&message_id='.$anchor->id.'&limit=1')
            ->assertOk()
            ->assertJsonFragment(['id' => $anchor->id]);
        $this->assertSame([$anchor->id], collect($anchored->json('data'))->pluck('id')->all());
        $this->assertNotNull($anchored->json('meta.older_cursor'));
        $this->assertNotNull($anchored->json('meta.newer_cursor'));

        $older = $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/messages?limit=1&cursor='.urlencode((string) $anchored->json('meta.older_cursor')))
            ->assertOk();
        $newer = $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/messages?limit=1&cursor='.urlencode((string) $anchored->json('meta.newer_cursor')))
            ->assertOk();
        $this->assertSame([$first->id], collect($older->json('data'))->pluck('id')->all());
        $this->assertSame([$third->id], collect($newer->json('data'))->pluck('id')->all());

        $latest = $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/messages?anchor=latest&limit=1')
            ->assertOk();
        $this->assertSame([$third->id], collect($latest->json('data'))->pluck('id')->all());
        $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/messages?anchor=first_unread&limit=10')
            ->assertOk();

        $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/messages?anchor=message&message_id='.$foreign->id)
            ->assertUnprocessable();
        $purged = $this->message($tenant, $inbox, $conversation, MessageKind::Text);
        $purged->forceFill(['purged_at' => now()])->save();
        $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/messages?anchor=message&message_id='.$purged->id)
            ->assertUnprocessable();
        $revoked = $this->message($tenant, $inbox, $conversation, MessageKind::Text);
        $revoked->forceFill(['revoked_at' => now()])->save();
        $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/messages?anchor=message&message_id='.$revoked->id)
            ->assertUnprocessable();
    }

    public function test_outbound_start_is_idempotent_and_conflicts_across_inboxes(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $firstInbox = $this->inbox($tenant, 'Primeira', '+5511000000005');
        $secondInbox = $this->inbox($tenant, 'Segunda', '+5511000000006');
        $this->member($firstInbox, $operator);
        $this->member($secondInbox, $operator);
        [$contact, $identity] = $this->contact($tenant, '+5511999990004');
        $this->authenticate($operator);

        $payload = [
            'contact_id' => $contact->id,
            'identity_id' => $identity->id,
            'inbox_id' => $firstInbox->id,
            'body' => 'Primeiro contato',
        ];
        $headers = ['Accept' => 'application/json', 'Idempotency-Key' => 'start-conversation-0001'];

        $created = $this->post('/api/v1/communication/conversations', $payload, $headers)
            ->assertAccepted()
            ->assertJsonPath('data.reused_conversation', false);
        $conversationId = $created->json('data.conversation.id');
        $messageId = $created->json('data.message.id');

        config(['communication.outbound_conversation.kill_switch' => true]);
        $this->post('/api/v1/communication/conversations', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.conversation.id', $conversationId)
            ->assertJsonPath('data.message.id', $messageId);

        $this->post('/api/v1/communication/conversations', $payload, [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'start-conversation-kill-switch-new-0001',
        ])->assertStatus(403);

        $this->post('/api/v1/communication/conversations', [
            ...$payload,
            'inbox_id' => $secondInbox->id,
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, CommunicationMessage::query()->withoutGlobalScopes()->where('direction', MessageDirection::Outbound)->count());
    }

    public function test_message_idempotency_is_namespaced_between_composer_and_outbound_initiation(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Namespace', '+5511000000040');
        $this->member($inbox, $operator);
        [$contact, $identity] = $this->contact($tenant, '+5511999990040');
        $conversation = $this->conversation($tenant, $inbox, $identity);
        $this->authenticate($operator);

        $directFirst = $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => 'Mesmo conteúdo A',
            'idempotency_key' => 'cross-endpoint-direct-first-0001',
        ])->assertAccepted();
        $directFirstReplay = $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => 'Mesmo conteúdo A',
            'idempotency_key' => 'cross-endpoint-direct-first-0001',
        ])->assertOk();
        $startAfterDirect = $this->post('/api/v1/communication/conversations', [
            'contact_id' => $contact->id,
            'identity_id' => $identity->id,
            'inbox_id' => $inbox->id,
            'body' => 'Mesmo conteúdo A',
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'cross-endpoint-direct-first-0001',
        ])->assertAccepted();
        $startAfterDirectReplay = $this->post('/api/v1/communication/conversations', [
            'contact_id' => $contact->id,
            'identity_id' => $identity->id,
            'inbox_id' => $inbox->id,
            'body' => 'Mesmo conteúdo A',
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'cross-endpoint-direct-first-0001',
        ])->assertOk();

        $startFirst = $this->post('/api/v1/communication/conversations', [
            'contact_id' => $contact->id,
            'identity_id' => $identity->id,
            'inbox_id' => $inbox->id,
            'body' => 'Mesmo conteúdo B',
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'cross-endpoint-start-first-0001',
        ])->assertAccepted();
        $directAfterStart = $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => 'Mesmo conteúdo B',
            'idempotency_key' => 'cross-endpoint-start-first-0001',
        ])->assertAccepted();

        $this->assertSame($directFirst->json('data.id'), $directFirstReplay->json('data.id'));
        $this->assertSame($startAfterDirect->json('data.message.id'), $startAfterDirectReplay->json('data.message.id'));
        $this->assertNotSame($directFirst->json('data.id'), $startAfterDirect->json('data.message.id'));
        $this->assertNotSame($startFirst->json('data.message.id'), $directAfterStart->json('data.id'));
        $this->assertSame(4, CommunicationMessage::query()->withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound)
            ->count());
    }

    public function test_composer_replays_the_pre_rollout_idempotency_formula(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Compatibilidade', '+5511000000044');
        $this->member($inbox, $operator);
        $membership = TenantMembership::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $operator->id)
            ->firstOrFail();
        [, $identity] = $this->contact($tenant, '+5511999990044');
        $conversation = $this->conversation($tenant, $inbox, $identity);
        $key = 'composer-replay-0001';
        $body = 'Mensagem aceita antes do rollout';
        $providerId = 'message-'.substr(hash('sha256', $key), 0, 40);
        $contentDigest = hash('sha256', implode('|', [
            MessageKind::Text->value,
            $body,
            '',
            '',
            'media',
            '[]',
            '',
        ]));
        $message = CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $identity->id,
            'author_membership_id' => $membership->id,
            'direction' => MessageDirection::Outbound,
            'kind' => MessageKind::Text,
            'source' => MessageSource::Human,
            'status' => MessageStatus::Queued,
            'body_encrypted' => $body,
            'provider_message_id' => $providerId,
            'content_digest' => $contentDigest,
            'occurred_at' => now(),
        ]);
        CommunicationOutboxEntry::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'message_id' => $message->id,
            'command_id' => 'pre-rollout-command-0001',
            'session_id' => $inbox->session_id,
            'type' => 'MESSAGE_SEND',
            'payload_encrypted' => ['provider_message_id' => $providerId],
            'payload_digest' => hash('sha256', $providerId),
            'status' => 'PENDING',
            'available_at' => now(),
        ]);
        $this->authenticate($operator);

        $response = $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => $body,
            'idempotency_key' => $key,
        ])->assertOk();

        $this->assertSame($message->id, $response->json('data.id'));
        $this->assertSame(1, CommunicationMessage::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, CommunicationOutboxEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_initiation_namespace_cannot_collide_with_composer_key(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Namespace', '+5511000000045');
        $this->member($inbox, $operator);
        [$contact, $identity] = $this->contact($tenant, '+5511999990045');
        $conversation = $this->conversation($tenant, $inbox, $identity);
        $this->authenticate($operator);

        $directFirst = $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => 'Colisão canônica A',
            'idempotency_key' => 'outbound-initiation:composer-prefix-a',
        ])->assertAccepted();
        $startAfterDirect = $this->post('/api/v1/communication/conversations', [
            'contact_id' => $contact->id,
            'identity_id' => $identity->id,
            'inbox_id' => $inbox->id,
            'body' => 'Colisão canônica A',
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'composer-prefix-a',
        ])->assertAccepted();

        $startFirst = $this->post('/api/v1/communication/conversations', [
            'contact_id' => $contact->id,
            'identity_id' => $identity->id,
            'inbox_id' => $inbox->id,
            'body' => 'Colisão canônica B',
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'composer-prefix-b',
        ])->assertAccepted();
        $directAfterStart = $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => 'Colisão canônica B',
            'idempotency_key' => 'outbound-initiation:composer-prefix-b',
        ])->assertAccepted();

        $this->assertNotSame($directFirst->json('data.id'), $startAfterDirect->json('data.message.id'));
        $this->assertNotSame($startFirst->json('data.message.id'), $directAfterStart->json('data.id'));
    }

    public function test_outbound_reuses_identity_alias_conversation_and_blocks_self_chat_alias(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Alias', '+5511000000013');
        $this->member($inbox, $operator);
        [$contact, $identity] = $this->contact($tenant, '+5511999990013');
        $alias = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'canonical_identity_id' => $identity->id,
            'channel' => CommunicationChannel::WhatsApp,
            'address_encrypted' => '+5511999990014',
            'address_hash' => hash('sha256', '+5511999990014'),
            'address_masked' => '***0014',
            'is_active' => true,
        ]);
        $existing = $this->conversation($tenant, $inbox, $alias);
        $this->authenticate($operator);

        $this->post('/api/v1/communication/conversations', [
            'contact_id' => $contact->id,
            'identity_id' => $identity->id,
            'inbox_id' => $inbox->id,
            'body' => 'Reutilizar alias',
        ], ['Accept' => 'application/json', 'Idempotency-Key' => 'alias-reuse-0001'])
            ->assertAccepted()
            ->assertJsonPath('data.conversation.id', $existing->id)
            ->assertJsonPath('data.reused_conversation', true);

        $alias->forceFill([
            'address_encrypted' => '+5511000000013',
            'address_hash' => $inbox->address_hash,
        ])->save();
        $this->post('/api/v1/communication/conversations', [
            'contact_id' => $contact->id,
            'identity_id' => $identity->id,
            'inbox_id' => $inbox->id,
            'body' => 'Não enviar para si',
        ], ['Accept' => 'application/json', 'Idempotency-Key' => 'alias-self-chat-0001'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'outbound_self_chat_forbidden');
    }

    public function test_initiation_capability_requires_reply_and_defaults_closed(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Capability', '+5511000000007');
        $this->member($inbox, $viewer);
        $this->member($inbox, $operator);

        $this->authenticate($viewer);
        $this->getJson('/api/v1/communication/outbound-capabilities')
            ->assertOk()
            ->assertJsonPath('data.conversation_initiation.enabled', false)
            ->assertJsonPath('data.conversation_initiation.reason', 'permission_denied');

        $this->authenticate($operator);
        $this->getJson('/api/v1/communication/outbound-capabilities')
            ->assertOk()
            ->assertJsonPath('data.conversation_initiation.enabled', true);

        config(['communication.outbound_conversation.kill_switch' => true]);
        $this->getJson('/api/v1/communication/outbound-capabilities')
            ->assertOk()
            ->assertJsonPath('data.conversation_initiation.enabled', false)
            ->assertJsonPath('data.conversation_initiation.reason', 'kill_switch_active');
    }

    public function test_outbound_start_attachment_permissions_allowlist_and_single_open_conversation(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $otherTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Anexo', '+5511000000030');
        $this->member($inbox, $viewer);
        $this->member($inbox, $operator);
        [$contact, $identity] = $this->contact($tenant, '+5511999990030');
        $this->authenticate($viewer);

        $payload = [
            'contact_id' => $contact->id,
            'identity_id' => $identity->id,
            'inbox_id' => $inbox->id,
            'body' => 'Sem permissão',
        ];
        $this->post('/api/v1/communication/conversations', $payload, [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'start-denied-0001',
        ])->assertForbidden();

        config([
            'communication.outbound_conversation.allow_all_tenants' => false,
            'communication.outbound_conversation.allowed_tenant_ids' => [$otherTenant->id],
        ]);
        $this->authenticate($operator);
        $this->post('/api/v1/communication/conversations', $payload, [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'start-not-allowlisted-0001',
        ])->assertForbidden()
            ->assertJsonPath('code', 'outbound_initiation_disabled');

        config([
            'communication.outbound_conversation.allowed_tenant_ids' => [$tenant->id],
        ]);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($png);
        $image = UploadedFile::fake()->createWithContent('inicio.png', $png);
        $created = $this->post('/api/v1/communication/conversations', [
            'contact_id' => $contact->id,
            'identity_id' => $identity->id,
            'inbox_id' => $inbox->id,
            'body' => 'Primeira imagem',
            'file' => $image,
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'start-image-0001',
        ])->assertAccepted()
            ->assertJsonPath('data.reused_conversation', false)
            ->assertJsonPath('data.message.kind', MessageKind::Image->value)
            ->assertJsonCount(1, 'data.message.attachments');
        $conversationId = (int) $created->json('data.conversation.id');

        $second = $this->post('/api/v1/communication/conversations', [
            'contact_id' => $contact->id,
            'identity_id' => $identity->id,
            'inbox_id' => $inbox->id,
            'body' => 'Segunda mensagem na mesma OPEN',
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'start-reuse-open-0001',
        ])->assertAccepted()
            ->assertJsonPath('data.conversation.id', $conversationId)
            ->assertJsonPath('data.reused_conversation', true);

        $this->assertSame(
            1,
            CommunicationConversation::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('inbox_id', $inbox->id)
                ->where('identity_id', $identity->id)
                ->where('status', ConversationStatus::Open)
                ->whereNull('merged_into_conversation_id')
                ->count(),
        );
        $this->assertSame(2, CommunicationMessage::query()->withoutGlobalScopes()
            ->where('conversation_id', $conversationId)
            ->where('direction', MessageDirection::Outbound)
            ->count());
        $this->assertNotNull($second->json('data.message.id'));
    }

    public function test_outbound_start_accepts_every_supported_attachment_family(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Famílias', '+5511000000032');
        $this->member($inbox, $operator);
        [$contact, $identity] = $this->contact($tenant, '+5511999990032');
        $this->authenticate($operator);

        $webp = base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEALmk0mk0iIiIiIgBoSygABc6zbAAA', true);
        $this->assertIsString($webp);
        $cases = [
            ['AUDIO', UploadedFile::fake()->create('inicio.ogg', 8, 'audio/ogg'), true],
            ['VIDEO', UploadedFile::fake()->create('inicio.mp4', 8, 'video/mp4'), false],
            ['DOCUMENT', UploadedFile::fake()->createWithContent('inicio.pdf', '%PDF-inicio'), false],
            ['STICKER', UploadedFile::fake()->createWithContent('inicio.webp', $webp), false],
        ];

        foreach ($cases as $index => [$kind, $file, $ptt]) {
            $response = $this->post('/api/v1/communication/conversations', [
                'contact_id' => $contact->id,
                'identity_id' => $identity->id,
                'inbox_id' => $inbox->id,
                'body' => in_array($kind, ['AUDIO', 'STICKER'], true) ? '' : 'Primeiro anexo '.$kind,
                'kind' => $kind,
                'ptt' => $ptt,
                'file' => $file,
            ], [
                'Accept' => 'application/json',
                'Idempotency-Key' => 'start-family-'.($index + 1).'-0001',
            ])->assertAccepted()
                ->assertJsonPath('data.message.kind', $kind)
                ->assertJsonCount(1, 'data.message.attachments');

            $payload = CommunicationOutboxEntry::query()
                ->withoutGlobalScopes()
                ->where('message_id', (int) $response->json('data.message.id'))
                ->firstOrFail()
                ->payload_encrypted;
            $this->assertSame($kind, $payload['kind']);
            $this->assertSame($ptt, $payload['media']['ptt']);
        }
    }

    public function test_outbound_start_rolls_back_every_write_and_deletes_staged_blob(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Rollback', '+5511000000033');
        $this->member($inbox, $operator);
        [$contact, $identity] = $this->contact($tenant, '+5511999990033');
        $this->authenticate($operator);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION test_fail_outbound_initiation_event()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.type = 'MESSAGE_QUEUED' THEN
                    RAISE EXCEPTION 'rollback outbound initiation probe';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER test_fail_outbound_initiation_event
            BEFORE INSERT ON communication_events
            FOR EACH ROW EXECUTE FUNCTION test_fail_outbound_initiation_event();
            SQL);

        $this->withoutExceptionHandling();
        try {
            $this->post('/api/v1/communication/conversations', [
                'contact_id' => $contact->id,
                'identity_id' => $identity->id,
                'inbox_id' => $inbox->id,
                'body' => 'Deve reverter',
                'kind' => 'DOCUMENT',
                'file' => UploadedFile::fake()->createWithContent('rollback.pdf', '%PDF-rollback'),
            ], [
                'Accept' => 'application/json',
                'Idempotency-Key' => 'start-rollback-0001',
            ]);
            $this->fail('A trigger de rollback deveria interromper a iniciação.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('rollback outbound initiation probe', $error->getMessage());
        }

        $this->assertDatabaseMissing('communication_conversations', [
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
        ]);
        $this->assertDatabaseMissing('communication_messages', [
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'direction' => MessageDirection::Outbound->value,
        ]);
        $this->assertDatabaseCount('communication_attachments', 0);
        $this->assertDatabaseCount('communication_outbox_entries', 0);
        $this->assertDatabaseCount('communication_events', 0);
        $this->assertSame(
            [],
            glob(rtrim((string) config('communication.media.disk_root'), '/').'/*/*.media') ?: [],
        );
    }

    public function test_outbound_start_digest_conflict_and_disabled_flag_block_new_writes(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Flags', '+5511000000031');
        $this->member($inbox, $operator);
        [$contact, $identity] = $this->contact($tenant, '+5511999990031');
        $this->authenticate($operator);

        $headers = ['Accept' => 'application/json', 'Idempotency-Key' => 'start-digest-0001'];
        $this->post('/api/v1/communication/conversations', [
            'contact_id' => $contact->id,
            'identity_id' => $identity->id,
            'inbox_id' => $inbox->id,
            'body' => 'Corpo A',
        ], $headers)->assertAccepted();

        $this->post('/api/v1/communication/conversations', [
            'contact_id' => $contact->id,
            'identity_id' => $identity->id,
            'inbox_id' => $inbox->id,
            'body' => 'Corpo B diferente',
        ], $headers)->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_conflict');

        config(['communication.outbound_conversation.enabled' => false]);
        $this->post('/api/v1/communication/conversations', [
            'contact_id' => $contact->id,
            'identity_id' => $identity->id,
            'inbox_id' => $inbox->id,
            'body' => 'Bloqueado por flag',
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'start-disabled-0001',
        ])->assertForbidden()
            ->assertJsonPath('code', 'outbound_initiation_disabled');
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }

    private function inbox(Tenant $tenant, string $name, string $address): CommunicationInbox
    {
        return CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'session_id' => 'session-'.Str::ulid(),
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => '***'.substr($address, -4),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
        ]);
    }

    private function member(CommunicationInbox $inbox, User $user): void
    {
        $membership = TenantMembership::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('user_id', $user->id)
            ->firstOrFail();
        CommunicationInboxMember::query()->withoutGlobalScopes()->create([
            'tenant_id' => $inbox->tenant_id,
            'inbox_id' => $inbox->id,
            'tenant_membership_id' => $membership->id,
            'is_active' => true,
        ]);
    }

    /** @return array{CommunicationContact,CommunicationIdentity} */
    private function contact(Tenant $tenant, string $address): array
    {
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Contato '.substr($address, -4),
            'is_provisional' => false,
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

        return [$contact, $identity];
    }

    private function conversation(
        Tenant $tenant,
        CommunicationInbox $inbox,
        CommunicationIdentity $identity,
        ConversationStatus $status = ConversationStatus::Open,
    ): CommunicationConversation {
        return CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => $status,
            'last_message_at' => now(),
        ]);
    }

    private function message(
        Tenant $tenant,
        CommunicationInbox $inbox,
        CommunicationConversation $conversation,
        MessageKind $kind,
    ): CommunicationMessage {
        return CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $conversation->identity_id,
            'direction' => MessageDirection::Inbound,
            'kind' => $kind,
            'source' => MessageSource::Gateway,
            'status' => MessageStatus::Delivered,
            'body_encrypted' => $kind === MessageKind::Text ? 'Mensagem' : null,
            'provider_message_id' => 'provider-'.strtolower((string) Str::ulid()),
            'content_digest' => hash('sha256', (string) Str::ulid()),
            'occurred_at' => now(),
        ]);
    }

    private function attachment(
        Tenant $tenant,
        CommunicationMessage $message,
        string $filename,
        string $mime,
    ): CommunicationAttachment {
        return CommunicationAttachment::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'message_id' => $message->id,
            'object_id' => (string) Str::ulid(),
            'original_name_encrypted' => $filename,
            'mime_type' => $mime,
            'size_bytes' => 1024,
            'sha256' => hash('sha256', $filename),
        ]);
    }

    /** @param array<string,mixed> $patch */
    private function mutateCursor(string $cursor, array $patch): string
    {
        [$encoded, $signature] = array_pad(explode('.', $cursor, 2), 2, null);
        $this->assertIsString($encoded);
        $this->assertIsString($signature);
        $raw = base64_decode(strtr($encoded.str_repeat('=', (4 - strlen($encoded) % 4) % 4), '-_', '+/'), true);
        $this->assertIsString($raw);
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $tampered = rtrim(strtr(base64_encode(json_encode([
            ...$payload,
            ...$patch,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $tampered.'.'.$signature;
    }
}
