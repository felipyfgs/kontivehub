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
use App\Events\CommunicationEventCommitted;
use App\Models\Client;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationConversationReadState;
use App\Models\CommunicationConversationUnreadMessage;
use App\Models\CommunicationEvent;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxIdentityProfile;
use App\Models\CommunicationInboxMember;
use App\Models\CommunicationLabel;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Communication\Media\MediaStore;
use App\Support\CurrentTenant;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CommunicationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake([CommunicationEventCommitted::class]);
        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
            'communication.media.disk_root' => sys_get_temp_dir().'/communication-api-tests-'.Str::ulid(),
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-reverb-key',
            'broadcasting.connections.reverb.secret' => 'test-reverb-secret',
            'broadcasting.connections.reverb.app_id' => 'test-reverb-app',
        ]);
        foreach (Broadcast::connection('null')->getChannels() as $pattern => $callback) {
            Broadcast::connection('reverb')->channel($pattern, $callback);
        }
    }

    public function test_rbac_limits_non_admin_to_membership_and_hides_foreign_tenant(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        $foreignAdmin = User::factory()->forTenant($foreignTenant, TenantRole::TenantAdmin)->create();
        $visible = $this->inbox($tenant, 'Fila fiscal');
        $restricted = $this->inbox($tenant, 'Fila diretoria');
        $foreign = $this->inbox($foreignTenant, 'Fila estrangeira');
        $this->member($visible, $operator);
        $this->member($visible, $viewer);
        $visibleConversation = $this->conversation($tenant, $visible, '+5511999991001');
        $restrictedConversation = $this->conversation($tenant, $restricted, '+5511999991002');
        $foreignConversation = $this->conversation($foreignTenant, $foreign, '+5511999991003');

        $this->authenticate($operator);
        $this->getJson('/api/v1/communication/inboxes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
        $this->getJson('/api/v1/communication/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleConversation->id);
        $this->getJson('/api/v1/communication/conversations/'.$restrictedConversation->id)->assertForbidden();
        $this->getJson('/api/v1/communication/conversations/'.$foreignConversation->id)->assertNotFound();
        $this->postJson('/api/v1/communication/inboxes', ['name' => 'Sem permissão'])->assertForbidden();

        $this->authenticate($admin);
        $this->getJson('/api/v1/communication/inboxes')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/communication/inboxes', [
            'name' => 'Nova inbox',
            'is_default' => true,
        ])->assertCreated();
        $this->assertSame(1, CommunicationInbox::query()->where('is_default', true)->count());

        $this->authenticate($viewer);
        $this->getJson('/api/v1/communication/conversations/'.$visibleConversation->id)->assertOk();
        $this->postJson('/api/v1/communication/conversations/'.$visibleConversation->id.'/messages', [
            'body' => 'Não deve sair',
            'idempotency_key' => 'viewer-denied-0001',
        ])->assertForbidden();

        $this->authenticate($foreignAdmin);
        $this->getJson('/api/v1/communication/conversations/'.$visibleConversation->id)->assertNotFound();
    }

    public function test_conversation_list_projects_only_latest_message_with_private_attachments(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $visible = $this->inbox($tenant, 'Atendimento');
        $restricted = $this->inbox($tenant, 'Diretoria');
        $this->member($visible, $operator);
        $conversation = $this->conversation($tenant, $visible, '+5511999991051');
        $restrictedConversation = $this->conversation($tenant, $restricted, '+5511999991052');
        $occurredAt = now()->startOfSecond();
        $older = $this->message($tenant, $visible, $conversation, 'Mensagem anterior');
        $older->forceFill(['occurred_at' => $occurredAt])->save();
        $image = $this->message($tenant, $visible, $conversation, 'Comprovante recebido');
        $image->forceFill([
            'kind' => MessageKind::Image,
            'occurred_at' => $occurredAt,
        ])->save();
        $attachment = CommunicationAttachment::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'message_id' => $image->id,
            'object_id' => (string) Str::ulid(),
            'original_name_encrypted' => 'comprovante.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => 2048,
            'sha256' => hash('sha256', 'comprovante'),
        ]);
        $conversation->forceFill(['last_message_at' => $occurredAt])->save();
        $restrictedImage = $this->message($tenant, $restricted, $restrictedConversation, 'Imagem restrita');
        $restrictedImage->forceFill(['kind' => MessageKind::Image, 'occurred_at' => $occurredAt])->save();

        $this->authenticate($operator);
        $response = $this->getJson('/api/v1/communication/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.last_message.id', $image->id)
            ->assertJsonPath('data.0.last_message.kind', MessageKind::Image->value)
            ->assertJsonPath('data.0.last_message.direction', MessageDirection::Inbound->value)
            ->assertJsonPath('data.0.last_message.attachments.0.id', $attachment->id)
            ->assertJsonPath('data.0.last_message.attachments.0.preview_url',
                '/api/v1/communication/attachments/'.$attachment->id.'/preview');

        $summary = $response->json('data.0');
        $this->assertIsArray($summary);
        $this->assertArrayNotHasKey('messages', $summary);
        $this->assertArrayNotHasKey('provider_message_id', $summary['last_message']);
        $this->assertArrayNotHasKey('object_id', $summary['last_message']['attachments'][0]);
    }

    public function test_conversation_boundaries_reject_tenant_input_and_manage_labels(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999991061');
        $label = CommunicationLabel::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Prioridade',
            'color' => 'red',
        ]);
        $foreignLabel = CommunicationLabel::query()->withoutGlobalScopes()->create([
            'tenant_id' => $foreignTenant->id,
            'name' => 'Estrangeira',
            'color' => 'blue',
        ]);
        $this->authenticate($operator);

        $index = $this->getJson('/api/v1/communication/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertSame([
            'current_page' => 1,
            'last_page' => 1,
            'total' => 1,
        ], $index->json('meta'));
        $this->assertArrayNotHasKey('links', $index->json());

        $this->getJson('/api/v1/communication/conversations?tenant_id='.$foreignTenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
        $this->getJson(
            '/api/v1/communication/conversations/'.$conversation->id.'?tenant_id='.$foreignTenant->id,
        )->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->patchJson('/api/v1/communication/conversations/'.$conversation->id, [
            'tenant_id' => $foreignTenant->id,
            'lock_version' => 1,
            'priority' => 50,
        ])->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'tenant_id' => $foreignTenant->id,
            'body' => 'Não deve persistir',
            'idempotency_key' => 'tenant-rejected-message-0001',
        ])->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->putJson(
            '/api/v1/communication/conversations/'.$conversation->id.'/labels/'.$label->id,
            ['tenant_id' => $foreignTenant->id],
        )->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->assertDatabaseMissing('communication_conversation_labels', [
            'conversation_id' => $conversation->id,
            'label_id' => $label->id,
        ]);

        $this->putJson(
            '/api/v1/communication/conversations/'.$conversation->id.'/labels/'.$foreignLabel->id,
        )->assertNotFound();
        $this->putJson(
            '/api/v1/communication/conversations/'.$conversation->id.'/labels/'.$label->id,
        )->assertCreated()->assertExactJson([
            'data' => ['label_id' => $label->id],
        ]);
        $this->assertDatabaseHas('communication_conversation_labels', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'label_id' => $label->id,
        ]);

        $this->deleteJson(
            '/api/v1/communication/conversations/'.$conversation->id.'/labels/'.$label->id,
            ['tenant_id' => $foreignTenant->id],
        )->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->assertDatabaseHas('communication_conversation_labels', [
            'conversation_id' => $conversation->id,
            'label_id' => $label->id,
        ]);
        $this->deleteJson(
            '/api/v1/communication/conversations/'.$conversation->id.'/labels/'.$label->id,
        )->assertNoContent();
        $this->assertDatabaseMissing('communication_conversation_labels', [
            'conversation_id' => $conversation->id,
            'label_id' => $label->id,
        ]);
        $this->assertDatabaseCount('communication_messages', 0);
        $this->assertDatabaseCount('communication_outbox_entries', 0);
    }

    public function test_conversation_update_rejections_roll_back_state_and_event(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $unassignedUser = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999991062');
        $unassignedMembership = TenantMembership::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $unassignedUser->id)
            ->firstOrFail();
        $this->authenticate($operator);

        $this->patchJson('/api/v1/communication/conversations/'.$conversation->id, [
            'lock_version' => 1,
            'status' => ConversationStatus::Snoozed->value,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'snoozed_until_required');
        $this->patchJson('/api/v1/communication/conversations/'.$conversation->id, [
            'lock_version' => 1,
            'assignee_membership_id' => $unassignedMembership->id,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'assignee_inbox_access_required');

        $conversation->refresh();
        $this->assertSame(1, $conversation->lock_version);
        $this->assertSame(ConversationStatus::Open, $conversation->status);
        $this->assertNull($conversation->assignee_membership_id);
        $this->assertDatabaseMissing('communication_events', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'type' => 'CONVERSATION_UPDATED',
        ]);

        $this->patchJson('/api/v1/communication/conversations/'.$conversation->id, [
            'lock_version' => 1,
            'status' => ConversationStatus::Resolved->value,
        ])->assertOk()
            ->assertJsonPath('data.status', ConversationStatus::Resolved->value)
            ->assertJsonPath('data.lock_version', 2);
        $this->assertDatabaseHas('communication_events', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'type' => 'CONVERSATION_UPDATED',
        ]);

        $this->patchJson('/api/v1/communication/conversations/'.$conversation->id, [
            'lock_version' => 2,
            'snoozed_until' => now()->addHour()->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('data.status', ConversationStatus::Snoozed->value)
            ->assertJsonPath('data.resolved_at', null);
        $this->assertNull($conversation->refresh()->resolved_at);
    }

    public function test_conversation_search_matches_linked_client_names_without_leaking_other_conversations(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $foreignOperator = User::factory()->forTenant($foreignTenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $foreignInbox = $this->inbox($foreignTenant, 'Atendimento estrangeiro');
        $this->member($inbox, $operator);
        $this->member($foreignInbox, $foreignOperator);
        $matched = $this->conversation($tenant, $inbox, '+5511999991101');
        $other = $this->conversation($tenant, $inbox, '+5511999991102');
        $foreign = $this->conversation($foreignTenant, $foreignInbox, '+5511999991103');
        $client = Client::factory()->create([
            'tenant_id' => $tenant->id,
            'display_name' => 'Mercado Aurora',
            'legal_name' => 'Aurora Comércio de Alimentos Ltda',
        ]);
        $foreignClient = Client::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'display_name' => 'Mercado Aurora Exterior',
        ]);
        $matched->clients()->attach($client->id, ['tenant_id' => $tenant->id]);
        $foreign->clients()->attach($foreignClient->id, ['tenant_id' => $foreignTenant->id]);

        $this->authenticate($operator);
        $response = $this->getJson('/api/v1/communication/conversations?q=aurora')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matched->id)
            ->assertJsonPath('data.0.clients.0.name', 'Mercado Aurora');
        $conversationIds = array_column($response->json('data'), 'id');
        self::assertNotContains($other->id, $conversationIds);
        self::assertNotContains($foreign->id, $conversationIds);

        $this->getJson('/api/v1/communication/conversations?q=comércio')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matched->id);
    }

    public function test_composer_notes_idempotency_and_optimistic_lock_are_enforced(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999992001');
        $this->authenticate($operator);

        $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => 'Nota apenas interna',
            'internal_note' => true,
        ])->assertCreated()
            ->assertJsonPath('data.kind', MessageKind::Note->value)
            ->assertJsonPath('data.direction', MessageDirection::Internal->value)
            ->assertJsonCount(0, 'data.attachments');
        $this->assertDatabaseCount('communication_outbox_entries', 0);
        Event::assertDispatched(CommunicationEventCommitted::class, static function (CommunicationEventCommitted $event) use ($inbox, $conversation): bool {
            return $event->inboxId === (int) $inbox->id
                && $event->conversationId === (int) $conversation->id
                && $event->broadcastAs() === 'communication.event';
        });

        $payload = ['body' => 'Resposta ao cliente', 'idempotency_key' => 'reply-idempotent-0001'];
        $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.status', MessageStatus::Queued->value);
        $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', $payload)->assertOk();
        $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            ...$payload,
            'body' => 'Outro conteúdo',
        ])->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('communication_outbox_entries', 1);
        $this->assertDatabaseCount('communication_messages', 2);

        $mediaResponse = $this->post('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => 'Segue o documento solicitado.',
            'idempotency_key' => 'reply-document-0001',
            'file' => UploadedFile::fake()->createWithContent('guia.pdf', '%PDF-conteudo-manual'),
        ], ['Accept' => 'application/json'])->assertStatus(202)
            ->assertJsonPath('data.kind', MessageKind::Document->value)
            ->assertJsonCount(1, 'data.attachments');
        $attachmentId = (int) $mediaResponse->json('data.attachments.0.id');
        $this->assertDatabaseCount('communication_outbox_entries', 2);
        $this->assertDatabaseHas('communication_attachments', ['id' => $attachmentId, 'mime_type' => 'application/pdf']);
        $download = $this->get('/api/v1/communication/attachments/'.$attachmentId.'/download')->assertOk();
        $this->assertSame('%PDF-conteudo-manual', $download->streamedContent());

        $version = $conversation->refresh()->lock_version;
        $this->patchJson('/api/v1/communication/conversations/'.$conversation->id, [
            'lock_version' => $version,
            'priority' => 50,
        ])->assertOk()->assertJsonPath('data.priority', 50);
        $this->patchJson('/api/v1/communication/conversations/'.$conversation->id, [
            'lock_version' => $version,
            'priority' => 90,
        ])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    }

    public function test_composer_preserves_remote_quote_audio_ptt_and_sticker_kind(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento rico');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999992101');
        $quoted = $this->message($tenant, $inbox, $conversation, 'Mensagem anterior');
        $this->authenticate($operator);
        $capabilities = $this->getJson('/api/v1/communication/outbound-capabilities')
            ->assertOk()
            ->assertJsonPath('data.kinds.LOCATION.supported', true)
            ->assertJsonPath('data.kinds.UNSUPPORTED.error_code', 'MESSAGE_KIND_UNSUPPORTED');
        $this->assertStringContainsString('private', (string) $capabilities->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $capabilities->headers->get('Cache-Control'));

        $locationResponse = $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'kind' => 'LOCATION',
            'location' => [
                'latitude' => -23.5505,
                'longitude' => -46.6333,
                'name' => 'São Paulo',
            ],
            'idempotency_key' => 'location-outbound-0001',
        ])->assertStatus(202)->assertJsonPath('data.kind', 'LOCATION');
        $locationMessage = CommunicationMessage::query()->withoutGlobalScopes()
            ->findOrFail((int) $locationResponse->json('data.id'));
        $this->assertSame(-23.5505, $locationMessage->content_encrypted['location']['latitude']);
        $this->assertSame(
            'LOCATION',
            CommunicationOutboxEntry::query()->withoutGlobalScopes()
                ->where('message_id', $locationMessage->id)->firstOrFail()->payload_encrypted['kind'],
        );

        foreach ([
            'CONTACT' => ['contact' => ['display_name' => 'Cliente', 'vcard' => "BEGIN:VCARD\nFN:Cliente\nEND:VCARD"]],
            'POLL' => ['poll' => ['name' => 'Escolha', 'options' => ['A', 'B'], 'selectable_options' => 1]],
            'INTERACTIVE' => ['interactive' => [
                'mode' => 'BUTTONS', 'title' => 'Confirma?', 'options' => ['Sim', 'Não'],
            ]],
        ] as $richKind => $richDto) {
            $response = $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
                'kind' => $richKind,
                ...$richDto,
                'idempotency_key' => 'rich-'.strtolower($richKind).'-0001',
            ])->assertStatus(202)->assertJsonPath('data.kind', $richKind);
            $richMessage = CommunicationMessage::query()->withoutGlobalScopes()
                ->findOrFail((int) $response->json('data.id'));
            $this->assertNotEmpty($richMessage->content_encrypted);
            $this->assertSame(
                $richKind,
                CommunicationOutboxEntry::query()->withoutGlobalScopes()
                    ->where('message_id', $richMessage->id)->firstOrFail()->payload_encrypted['kind'],
            );
        }

        $previewResponse = $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'kind' => 'TEXT',
            'body' => 'Veja',
            'link_preview' => [
                'url' => 'https://example.com/item',
                'title' => 'Item',
                'description' => 'Descrição',
            ],
            'idempotency_key' => 'link-preview-outbound-0001',
        ])->assertStatus(202);
        $previewMessage = CommunicationMessage::query()->withoutGlobalScopes()
            ->findOrFail((int) $previewResponse->json('data.id'));
        $this->assertSame(
            'https://example.com/item',
            $previewMessage->content_encrypted['link_preview']['url'],
        );

        $beforeInvalidRich = CommunicationMessage::query()->withoutGlobalScopes()->count();
        $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'kind' => 'LOCATION',
            'location' => ['latitude' => 0, 'longitude' => 0],
            'contact' => ['display_name' => 'X', 'vcard' => 'BEGIN:VCARD'],
        ])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'kind' => 'LOCATION',
            'body' => 'Legenda incompatível',
            'location' => ['latitude' => 0, 'longitude' => 0],
        ])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'internal_note' => true,
            'body' => 'nota',
            'location' => ['latitude' => 0, 'longitude' => 0],
        ])->assertUnprocessable()->assertJsonValidationErrors('internal_note');
        $this->assertSame($beforeInvalidRich, CommunicationMessage::query()->withoutGlobalScopes()->count());

        $messagesBeforeUnsupported = CommunicationMessage::query()->withoutGlobalScopes()->count();
        $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'kind' => 'UNSUPPORTED',
            'body' => 'não converter',
            'idempotency_key' => 'unsupported-outbound-0001',
        ])->assertUnprocessable()->assertJsonPath('code', 'MESSAGE_KIND_UNSUPPORTED');
        $this->assertSame(
            $messagesBeforeUnsupported,
            CommunicationMessage::query()->withoutGlobalScopes()->count(),
        );

        $audio = UploadedFile::fake()->create('voz.ogg', 8, 'audio/ogg');
        $audioResponse = $this->post('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => '',
            'kind' => 'AUDIO',
            'ptt' => true,
            'reply_to_message_id' => $quoted->id,
            'idempotency_key' => 'reply-audio-ptt-0001',
            'file' => $audio,
        ], ['Accept' => 'application/json'])->assertStatus(202)
            ->assertJsonPath('data.kind', MessageKind::Audio->value)
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.reply_to_message_id', $quoted->id)
            ->assertJsonPath('data.attachments.0.filename', 'voz.ogg')
            ->assertJsonPath('data.attachments.0.preview_url', fn (mixed $value): bool => is_string($value) && str_ends_with($value, '/preview'));

        $audioMessageId = (int) $audioResponse->json('data.id');
        $audioPayload = CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->where('message_id', $audioMessageId)->firstOrFail()->payload_encrypted;
        $this->assertSame('AUDIO', $audioPayload['kind']);
        $this->assertTrue($audioPayload['media']['ptt']);
        $this->assertSame($quoted->provider_message_id, $audioPayload['reply_to']['message_id']);
        $this->assertSame($conversation->identity->address_encrypted, $audioPayload['reply_to']['sender']);
        $this->assertArrayNotHasKey('text', $audioPayload);

        $webp = base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEALmk0mk0iIiIiIgBoSygABc6zbAAA', true);
        $this->assertIsString($webp);
        $sticker = UploadedFile::fake()->createWithContent('aceno.webp', $webp);
        $stickerResponse = $this->post('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => '',
            'kind' => 'STICKER',
            'idempotency_key' => 'sticker-webp-0001',
            'file' => $sticker,
        ], ['Accept' => 'application/json'])->assertStatus(202)
            ->assertJsonPath('data.kind', MessageKind::Sticker->value)
            ->assertJsonPath('data.body', null);
        $stickerPayload = CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->where('message_id', (int) $stickerResponse->json('data.id'))->firstOrFail()->payload_encrypted;
        $this->assertSame('STICKER', $stickerPayload['kind']);
        $this->assertArrayNotHasKey('caption', $stickerPayload);

        $beforeMessages = CommunicationMessage::query()->withoutGlobalScopes()->count();
        $beforeCommands = CommunicationOutboxEntry::query()->withoutGlobalScopes()->count();
        $this->post('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => '',
            'kind' => 'AUDIO',
            'idempotency_key' => 'invalid-sticker-kind-0001',
            'file' => UploadedFile::fake()->createWithContent('invalido.webp', $webp),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->post('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => 'não é voz',
            'kind' => 'DOCUMENT',
            'ptt' => true,
            'idempotency_key' => 'invalid-ptt-kind-0001',
            'file' => UploadedFile::fake()->create('arquivo.pdf', 2, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('ptt');
        $this->assertSame($beforeMessages, CommunicationMessage::query()->withoutGlobalScopes()->count());
        $this->assertSame($beforeCommands, CommunicationOutboxEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_cursor_sync_broadcast_and_private_download_follow_inbox_access(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $foreignAdmin = User::factory()->forTenant($foreignTenant, TenantRole::TenantAdmin)->create();
        $visible = $this->inbox($tenant, 'Visível');
        $restricted = $this->inbox($tenant, 'Restrita');
        $this->member($visible, $operator);
        $conversation = $this->conversation($tenant, $visible, '+5511999993001');
        $restrictedConversation = $this->conversation($tenant, $restricted, '+5511999993002');
        $message = $this->message($tenant, $visible, $conversation, 'anexo privado');
        $this->message($tenant, $restricted, $restrictedConversation, 'segredo restrito');
        $first = $this->event($tenant, $visible, $conversation, $message, 'MESSAGE_CREATED');
        $this->event($tenant, $restricted, $restrictedConversation, null, 'RESTRICTED_EVENT');

        $metadata = [
            'tenant_id' => (int) $tenant->id,
            'inbox_id' => (int) $visible->id,
            'gateway_event_id' => 'gateway-download-0001',
            'sha256' => hash('sha256', 'conteudo privado'),
        ];
        $stored = app(MediaStore::class)->putStream(
            Utils::streamFor('conteudo privado'),
            $metadata,
        );
        $attachment = CommunicationAttachment::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'message_id' => $message->id,
            'object_id' => $stored['object_id'],
            'original_name_encrypted' => 'documento.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => $stored['size_bytes'],
            'sha256' => $stored['sha256'],
            'storage_context' => $metadata,
        ]);
        $imageBytes = "\x89PNG\r\n\x1a\npreview privado";
        $imageMetadata = [
            'tenant_id' => (int) $tenant->id,
            'inbox_id' => (int) $visible->id,
            'gateway_event_id' => 'gateway-preview-0001',
            'sha256' => hash('sha256', $imageBytes),
        ];
        $storedImage = app(MediaStore::class)->putStream(
            Utils::streamFor($imageBytes),
            $imageMetadata,
        );
        $imageAttachment = CommunicationAttachment::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'message_id' => $message->id,
            'object_id' => $storedImage['object_id'],
            'original_name_encrypted' => 'comprovante.png',
            'mime_type' => 'image/png',
            'size_bytes' => $storedImage['size_bytes'],
            'sha256' => $storedImage['sha256'],
            'storage_context' => $imageMetadata,
        ]);

        $this->authenticate($operator);
        $this->getJson('/api/v1/communication/events?after=0&tenant_id='.$tenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $events = $this->getJson('/api/v1/communication/events?after=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cursor', $first->id)
            ->assertJsonPath('meta.next_cursor', $first->id)
            ->assertJsonPath('meta.has_more', false);
        $this->assertSame([
            'cursor',
            'type',
            'inbox_id',
            'conversation_id',
            'message_id',
            'payload',
            'occurred_at',
        ], array_keys($events->json('data.0')));

        $this->get(
            '/api/v1/communication/attachments/'.$attachment->id.'/download?tenant_id='.$tenant->id,
        )->assertUnprocessable();
        $download = $this->get('/api/v1/communication/attachments/'.$attachment->id.'/download')->assertOk();
        $this->assertSame('conteudo privado', $download->streamedContent());
        $this->get('/api/v1/communication/attachments/'.$attachment->id.'/preview')->assertStatus(415);
        $preview = $this->get('/api/v1/communication/attachments/'.$imageAttachment->id.'/preview')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('private', (string) $preview->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline;', (string) $preview->headers->get('Content-Disposition'));
        $this->assertSame($imageBytes, $preview->streamedContent());

        $head = $this->call('HEAD', '/api/v1/communication/attachments/'.$imageAttachment->id.'/preview')
            ->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Length', (string) strlen($imageBytes));
        $this->assertSame('', $head->getContent());

        $range = $this->get(
            '/api/v1/communication/attachments/'.$imageAttachment->id.'/preview',
            ['Range' => 'bytes=2-7'],
        )->assertStatus(206)
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Range', 'bytes 2-7/'.strlen($imageBytes))
            ->assertHeader('Content-Length', '6');
        $this->assertSame(substr($imageBytes, 2, 6), $range->streamedContent());

        $multipleRanges = $this->get(
            '/api/v1/communication/attachments/'.$imageAttachment->id.'/preview',
            ['Range' => 'bytes=0-1,4-5'],
        )->assertOk()
            ->assertHeader('Content-Length', (string) strlen($imageBytes));
        $this->assertSame($imageBytes, $multipleRanges->streamedContent());

        $this->get(
            '/api/v1/communication/attachments/'.$imageAttachment->id.'/preview',
            ['Range' => 'bytes=999-1000'],
        )->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */'.strlen($imageBytes));
        $channel = Broadcast::connection('reverb')->getChannels()['communication.inbox.{inboxId}'];
        $this->assertTrue($channel($operator, (int) $visible->id));
        $this->assertFalse($channel($operator, (int) $restricted->id));
        $this->post('/api/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-communication.inbox.'.$visible->id,
        ])->assertOk();
        $this->post('/api/broadcasting/auth', [
            'socket_id' => '123.457',
            'channel_name' => 'private-communication.inbox.'.$restricted->id,
        ])->assertForbidden();

        $this->authenticate($admin);
        $this->getJson('/api/v1/communication/events?after='.$first->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'RESTRICTED_EVENT');
        $this->post('/api/broadcasting/auth', [
            'socket_id' => '123.458',
            'channel_name' => 'private-communication.inbox.'.$restricted->id,
        ])->assertOk();
        $this->post('/api/broadcasting/auth', [
            'socket_id' => '123.459',
            'channel_name' => 'private-communication.tenant.'.$tenant->id,
        ])->assertOk();

        $this->authenticate($foreignAdmin);
        $this->get('/api/v1/communication/attachments/'.$attachment->id.'/download')->assertNotFound();
        $this->get('/api/v1/communication/attachments/'.$imageAttachment->id.'/preview')->assertNotFound();
        $this->post('/api/broadcasting/auth', [
            'socket_id' => '123.460',
            'channel_name' => 'private-communication.inbox.'.$visible->id,
        ])->assertForbidden();

        $message->forceFill([
            'content_encrypted' => ['location' => ['latitude' => 0, 'longitude' => 0, 'live' => false]],
            'revoked_at' => now(),
        ])->save();
        $this->authenticate($operator);
        $this->getJson('/api/v1/communication/conversations/'.$conversation->id)
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', null)
            ->assertJsonPath('data.messages.0.content', null)
            ->assertJsonCount(0, 'data.messages.0.attachments');
        $this->get('/api/v1/communication/attachments/'.$attachment->id.'/download')->assertNotFound();
        $this->get('/api/v1/communication/attachments/'.$imageAttachment->id.'/preview')->assertNotFound();
        $this->assertTrue(app(MediaStore::class)->exists($stored['object_id']));
    }

    public function test_platform_privileged_broadcast_auth_follows_active_tenant(): void
    {
        config(['features.platform_privileged_context.enabled' => true]);

        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $otherTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant, 'Privileged');
        $otherInbox = $this->inbox($otherTenant, 'Outro');
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();

        Sanctum::actingAs($actor);
        app(CurrentTenant::class)->clear();
        app(CurrentTenant::class)->bindPlatformPrivileged($actor, $tenant);

        $this->post('/api/broadcasting/auth', [
            'socket_id' => '321.100',
            'channel_name' => 'private-communication.inbox.'.$inbox->id,
        ])->assertOk();
        $this->post('/api/broadcasting/auth', [
            'socket_id' => '321.101',
            'channel_name' => 'private-communication.tenant.'.$tenant->id,
        ])->assertOk();
        $this->post('/api/broadcasting/auth', [
            'socket_id' => '321.102',
            'channel_name' => 'private-communication.inbox.'.$otherInbox->id,
        ])->assertForbidden();
    }

    public function test_shared_contact_import_is_server_resolved_authorized_and_idempotent(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        $foreignAdmin = User::factory()->forTenant($foreignTenant, TenantRole::TenantAdmin)->create();
        $inbox = $this->inbox($tenant, 'Contatos compartilhados');
        $this->member($inbox, $viewer);
        $conversation = $this->conversation($tenant, $inbox, '+5511999995001');
        $message = $this->message($tenant, $inbox, $conversation, '');
        $sharedPhoneLines = collect(range(5002, 5012))
            ->map(fn (int $suffix): string => "TEL;TYPE=CELL;WAID=551199999{$suffix}:+551199999{$suffix}")
            ->implode("\r\n");
        $message->forceFill([
            'kind' => MessageKind::Contact,
            'body_encrypted' => null,
            'content_encrypted' => [
                'contacts' => [[
                    'display_name' => 'Contato compartilhado',
                    'vcard' => "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Contato compartilhado\r\n{$sharedPhoneLines}\r\nEND:VCARD",
                    'provider_secret' => 'não pode sair na API',
                ]],
            ],
        ])->save();
        $path = '/api/v1/communication/conversations/'.$conversation->id
            .'/messages/'.$message->id.'/contacts/0/save';

        $this->authenticate($viewer);
        $this->postJson($path, ['phone_index' => 0])->assertForbidden();

        $this->authenticate($foreignAdmin);
        $this->postJson($path, ['phone_index' => 0])->assertNotFound();

        $this->authenticate($admin);
        $this->getJson('/api/v1/communication/conversations/'.$conversation->id)
            ->assertOk()
            ->assertJsonPath('data.messages.0.content.contacts.0.display_name', 'Contato compartilhado')
            ->assertJsonPath('data.messages.0.content.contacts.0.phones.0.phone', '+5511999995002')
            ->assertJsonCount(10, 'data.messages.0.content.contacts.0.phones')
            ->assertJsonMissingPath('data.messages.0.content.contacts.0.provider_secret');
        $this->postJson($path, [
            'phone_index' => 0,
            'name' => 'Valor do cliente não pode ser aceito',
            'phone' => '+5511888888888',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'phone']);

        $this->postJson($path, ['phone_index' => 0])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'created')
            ->assertJsonPath('data.contact.name', 'Contato compartilhado');
        $this->postJson($path, ['phone_index' => 0])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'existing');
        $this->postJson($path, ['phone_index' => 9])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'created');
        $this->postJson($path, ['phone_index' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_index']);
        $this->assertSame(1, CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('address_hash', hash('sha256', '+5511999995002'))
            ->count());
        $this->assertSame(1, CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('address_hash', hash('sha256', '+5511999995011'))
            ->count());
    }

    public function test_admin_export_and_purge_remove_recoverable_content_and_keep_tombstone(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $inbox = $this->inbox($tenant, 'Privacidade');
        $conversation = $this->conversation($tenant, $inbox, '+5511999994001');
        $message = $this->message($tenant, $inbox, $conversation, 'conteúdo pessoal');
        $message->forceFill([
            'content_encrypted' => ['text' => 'conteúdo estruturado pessoal'],
            'metadata' => ['private' => 'metadado pessoal'],
        ])->save();
        $contact = $conversation->identity->contact;
        CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $conversation->identity_id,
            'address_book_full_name' => 'Nome privado da agenda',
        ]);
        CommunicationConversationUnreadMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
        ]);
        CommunicationConversationReadState::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'version' => 1,
        ]);
        $metadata = [
            'tenant_id' => (int) $tenant->id,
            'inbox_id' => (int) $inbox->id,
            'gateway_event_id' => 'gateway-purge-0001',
            'sha256' => hash('sha256', 'blob a remover'),
        ];
        $stored = app(MediaStore::class)->putStream(
            Utils::streamFor('blob a remover'),
            $metadata,
        );
        CommunicationAttachment::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'message_id' => $message->id,
            'object_id' => $stored['object_id'],
            'original_name_encrypted' => 'privado.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => $stored['size_bytes'],
            'sha256' => $stored['sha256'],
            'storage_context' => $metadata,
        ]);
        $invalidObjectId = 'objeto-invalido-para-retry';
        CommunicationAttachment::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'message_id' => $message->id,
            'object_id' => $invalidObjectId,
            'original_name_encrypted' => 'retry.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 1,
            'sha256' => hash('sha256', 'retry'),
            'storage_context' => $metadata,
        ]);
        $this->authenticate($admin);
        $donorContact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'merged_into_contact_id' => $contact->id,
            'name' => 'Nome duplicado sensível',
            'is_provisional' => false,
            'is_active' => false,
            'metadata' => ['private' => 'metadata duplicada sensível'],
        ]);

        $this->deleteJson(
            '/api/v1/communication/contacts/'.$contact->id.'/personal-data',
            ['tenant_id' => $tenant->id],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $export = $this->get('/api/v1/communication/contacts/'.$contact->id.'/export')->assertOk();
        $exported = json_decode(
            $export->streamedContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame(
            'conteúdo pessoal',
            data_get($exported, 'contact.identities.0.conversations.0.messages.0.body'),
        );
        $this->assertSame(
            'Nome privado da agenda',
            data_get($exported, 'contact.identities.0.inbox_profiles.0.address_book_full_name'),
        );
        $this->deleteJson('/api/v1/communication/contacts/'.$donorContact->id.'/personal-data')
            ->assertOk()
            ->assertJsonPath('data.contact_id', $contact->id)
            ->assertJsonPath('data.deleted_blobs', 1);

        $this->assertFalse(app(MediaStore::class)->exists($stored['object_id']));
        $this->assertNull($message->refresh()->body_encrypted);
        $this->assertNull($message->content_encrypted);
        $this->assertNull($message->metadata);
        $this->assertNotNull($message->purged_at);
        $this->assertSame(ConversationStatus::Resolved, $conversation->refresh()->status);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $conversation->tombstone_digest);
        $this->assertSame('[removido]', $conversation->identity->refresh()->address_masked);
        $donorContact->refresh();
        $this->assertNull($donorContact->name);
        $this->assertNull($donorContact->metadata);
        $this->assertNotNull($donorContact->purged_at);
        $this->assertDatabaseMissing('communication_inbox_identity_profiles', [
            'identity_id' => $conversation->identity_id,
        ]);
        $this->assertDatabaseMissing('communication_conversation_unread_messages', [
            'conversation_id' => $conversation->id,
        ]);
        $this->assertDatabaseMissing('communication_conversation_read_states', [
            'conversation_id' => $conversation->id,
        ]);
        $this->assertDatabaseHas('communication_events', ['type' => 'CONTACT_PURGED']);
        $readStateEvent = CommunicationEvent::query()->withoutGlobalScopes()
            ->where('type', 'conversation.read_state.updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(0, $readStateEvent->payload['unread_count'] ?? null);
        $this->assertSame(
            ['conversation_id', 'first_unread_message_id', 'inbox_id', 'last_read_through_message_id', 'unread_count', 'version'],
            collect(array_keys($readStateEvent->payload))->sort()->values()->all(),
        );
        $this->putJson('/api/v1/communication/conversations/'.$conversation->id.'/read-state', [
            'state' => 'READ',
            'through_message_id' => $message->id,
        ])->assertStatus(410)->assertJsonPath('code', 'conversation_purged');
        $this->assertDatabaseMissing('communication_media_deletion_intents', [
            'object_id' => $invalidObjectId,
            'tenant_id' => $tenant->id,
        ]);

        $this->postJson(
            '/api/v1/communication/contacts/'.$contact->id.'/identities',
            ['phone' => '+5511999994999'],
        )->assertStatus(410)
            ->assertJsonPath('code', 'contact_purged');
        $client = Client::factory()->for($tenant)->create();
        $this->postJson(
            '/api/v1/communication/identities/'.$conversation->identity_id.'/links',
            ['client_id' => $client->id],
        )->assertStatus(410)
            ->assertJsonPath('code', 'contact_purged');
        $this->assertDatabaseMissing('communication_identity_links', [
            'identity_id' => $conversation->identity_id,
            'client_id' => $client->id,
        ]);
        $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => 'não pode sobreviver à purga',
            'kind' => MessageKind::Text->value,
            'idempotency_key' => 'purged-conversation-message-0001',
        ])->assertStatus(410)
            ->assertJsonPath('code', 'conversation_purged');
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }

    private function inbox(Tenant $tenant, string $name): CommunicationInbox
    {
        return CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'session_id' => 'session-'.Str::ulid(),
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

    private function conversation(Tenant $tenant, CommunicationInbox $inbox, string $address): CommunicationConversation
    {
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Contato '.substr($address, -4),
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
            'last_message_at' => now(),
        ]);
        $identity->setRelation('contact', $contact);
        $conversation->setRelation('identity', $identity);

        return $conversation;
    }

    private function message(
        Tenant $tenant,
        CommunicationInbox $inbox,
        CommunicationConversation $conversation,
        string $body,
    ): CommunicationMessage {
        return CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $conversation->identity_id,
            'direction' => MessageDirection::Inbound,
            'kind' => MessageKind::Text,
            'source' => MessageSource::Gateway,
            'status' => MessageStatus::Delivered,
            'body_encrypted' => $body,
            'provider_message_id' => 'provider-'.strtolower((string) Str::ulid()),
            'content_digest' => hash('sha256', $body),
            'occurred_at' => now(),
        ]);
    }

    private function event(
        Tenant $tenant,
        CommunicationInbox $inbox,
        CommunicationConversation $conversation,
        ?CommunicationMessage $message,
        string $type,
    ): CommunicationEvent {
        return CommunicationEvent::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message?->id,
            'type' => $type,
            'payload' => ['safe' => true],
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
