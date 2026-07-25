<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\CommunicationChannel;
use App\Enums\OfficeRole;
use App\Events\CommunicationEventCommitted;
use App\Models\Client;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationEvent;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxMember;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\User;
use App\Services\Communication\Media\CommunicationMediaStore;
use App\Support\CurrentOffice;
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

    public function test_rbac_limits_non_admin_to_membership_and_hides_foreign_office(): void
    {
        $office = Office::factory()->create(['communication_enabled' => true]);
        $foreignOffice = Office::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        $operator = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();
        $foreignAdmin = User::factory()->forOffice($foreignOffice, OfficeRole::Admin)->create();
        $visible = $this->inbox($office, 'Fila fiscal');
        $restricted = $this->inbox($office, 'Fila diretoria');
        $foreign = $this->inbox($foreignOffice, 'Fila estrangeira');
        $this->member($visible, $operator);
        $this->member($visible, $viewer);
        $visibleConversation = $this->conversation($office, $visible, '+5511999991001');
        $restrictedConversation = $this->conversation($office, $restricted, '+5511999991002');
        $foreignConversation = $this->conversation($foreignOffice, $foreign, '+5511999991003');

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
        $office = Office::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $visible = $this->inbox($office, 'Atendimento');
        $restricted = $this->inbox($office, 'Diretoria');
        $this->member($visible, $operator);
        $conversation = $this->conversation($office, $visible, '+5511999991051');
        $restrictedConversation = $this->conversation($office, $restricted, '+5511999991052');
        $occurredAt = now()->startOfSecond();
        $older = $this->message($office, $visible, $conversation, 'Mensagem anterior');
        $older->forceFill(['occurred_at' => $occurredAt])->save();
        $image = $this->message($office, $visible, $conversation, 'Comprovante recebido');
        $image->forceFill([
            'kind' => MessageKind::Image,
            'occurred_at' => $occurredAt,
        ])->save();
        $attachment = CommunicationAttachment::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'message_id' => $image->id,
            'object_id' => (string) Str::ulid(),
            'original_name_encrypted' => 'comprovante.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => 2048,
            'sha256' => hash('sha256', 'comprovante'),
        ]);
        $conversation->forceFill(['last_message_at' => $occurredAt])->save();
        $restrictedImage = $this->message($office, $restricted, $restrictedConversation, 'Imagem restrita');
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

    public function test_conversation_search_matches_linked_client_names_without_leaking_other_conversations(): void
    {
        $office = Office::factory()->create(['communication_enabled' => true]);
        $foreignOffice = Office::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $foreignOperator = User::factory()->forOffice($foreignOffice, OfficeRole::Operator)->create();
        $inbox = $this->inbox($office, 'Atendimento');
        $foreignInbox = $this->inbox($foreignOffice, 'Atendimento estrangeiro');
        $this->member($inbox, $operator);
        $this->member($foreignInbox, $foreignOperator);
        $matched = $this->conversation($office, $inbox, '+5511999991101');
        $other = $this->conversation($office, $inbox, '+5511999991102');
        $foreign = $this->conversation($foreignOffice, $foreignInbox, '+5511999991103');
        $client = Client::factory()->create([
            'office_id' => $office->id,
            'display_name' => 'Mercado Aurora',
            'legal_name' => 'Aurora Comércio de Alimentos Ltda',
        ]);
        $foreignClient = Client::factory()->create([
            'office_id' => $foreignOffice->id,
            'display_name' => 'Mercado Aurora Exterior',
        ]);
        $matched->clients()->attach($client->id, ['office_id' => $office->id]);
        $foreign->clients()->attach($foreignClient->id, ['office_id' => $foreignOffice->id]);

        $this->authenticate($operator);
        $this->getJson('/api/v1/communication/conversations?q=aurora')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matched->id)
            ->assertJsonPath('data.0.clients.0.name', 'Mercado Aurora')
            ->assertJsonMissing(['id' => $other->id])
            ->assertJsonMissing(['id' => $foreign->id]);

        $this->getJson('/api/v1/communication/conversations?q=comércio')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matched->id);
    }

    public function test_composer_notes_idempotency_and_optimistic_lock_are_enforced(): void
    {
        $office = Office::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $inbox = $this->inbox($office, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($office, $inbox, '+5511999992001');
        $this->authenticate($operator);

        $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => 'Nota apenas interna',
            'internal_note' => true,
        ])->assertCreated()
            ->assertJsonPath('data.kind', MessageKind::Note->value)
            ->assertJsonPath('data.direction', MessageDirection::Internal->value);
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
        $office = Office::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $inbox = $this->inbox($office, 'Atendimento rico');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($office, $inbox, '+5511999992101');
        $quoted = $this->message($office, $inbox, $conversation, 'Mensagem anterior');
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
        $office = Office::factory()->create(['communication_enabled' => true]);
        $foreignOffice = Office::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        $foreignAdmin = User::factory()->forOffice($foreignOffice, OfficeRole::Admin)->create();
        $visible = $this->inbox($office, 'Visível');
        $restricted = $this->inbox($office, 'Restrita');
        $this->member($visible, $operator);
        $conversation = $this->conversation($office, $visible, '+5511999993001');
        $restrictedConversation = $this->conversation($office, $restricted, '+5511999993002');
        $message = $this->message($office, $visible, $conversation, 'anexo privado');
        $this->message($office, $restricted, $restrictedConversation, 'segredo restrito');
        $first = $this->event($office, $visible, $conversation, $message, 'MESSAGE_CREATED');
        $this->event($office, $restricted, $restrictedConversation, null, 'RESTRICTED_EVENT');

        $metadata = [
            'office_id' => (int) $office->id,
            'inbox_id' => (int) $visible->id,
            'gateway_event_id' => 'gateway-download-0001',
            'sha256' => hash('sha256', 'conteudo privado'),
        ];
        $stored = app(CommunicationMediaStore::class)->putStream(
            Utils::streamFor('conteudo privado'),
            $metadata,
        );
        $attachment = CommunicationAttachment::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
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
            'office_id' => (int) $office->id,
            'inbox_id' => (int) $visible->id,
            'gateway_event_id' => 'gateway-preview-0001',
            'sha256' => hash('sha256', $imageBytes),
        ];
        $storedImage = app(CommunicationMediaStore::class)->putStream(
            Utils::streamFor($imageBytes),
            $imageMetadata,
        );
        $imageAttachment = CommunicationAttachment::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'message_id' => $message->id,
            'object_id' => $storedImage['object_id'],
            'original_name_encrypted' => 'comprovante.png',
            'mime_type' => 'image/png',
            'size_bytes' => $storedImage['size_bytes'],
            'sha256' => $storedImage['sha256'],
            'storage_context' => $imageMetadata,
        ]);

        $this->authenticate($operator);
        $this->getJson('/api/v1/communication/events?after=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cursor', $first->id)
            ->assertJsonPath('meta.next_cursor', $first->id);
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
            'channel_name' => 'private-communication.office.'.$office->id,
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
        $this->assertTrue(app(CommunicationMediaStore::class)->exists($stored['object_id']));
    }

    public function test_platform_privileged_broadcast_auth_follows_active_office(): void
    {
        config(['features.platform_privileged_context.enabled' => true]);

        $office = Office::factory()->create(['communication_enabled' => true]);
        $otherOffice = Office::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($office, 'Privileged');
        $otherInbox = $this->inbox($otherOffice, 'Outro');
        $actor = User::factory()->asPlatformAdmin($office->id)->create();

        Sanctum::actingAs($actor);
        app(CurrentOffice::class)->clear();
        app(CurrentOffice::class)->bindPlatformPrivileged($actor, $office);

        $this->post('/api/broadcasting/auth', [
            'socket_id' => '321.100',
            'channel_name' => 'private-communication.inbox.'.$inbox->id,
        ])->assertOk();
        $this->post('/api/broadcasting/auth', [
            'socket_id' => '321.101',
            'channel_name' => 'private-communication.office.'.$office->id,
        ])->assertOk();
        $this->post('/api/broadcasting/auth', [
            'socket_id' => '321.102',
            'channel_name' => 'private-communication.inbox.'.$otherInbox->id,
        ])->assertForbidden();
    }

    public function test_admin_export_and_purge_remove_recoverable_content_and_keep_tombstone(): void
    {
        $office = Office::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        $inbox = $this->inbox($office, 'Privacidade');
        $conversation = $this->conversation($office, $inbox, '+5511999994001');
        $message = $this->message($office, $inbox, $conversation, 'conteúdo pessoal');
        $contact = $conversation->identity->contact;
        $metadata = [
            'office_id' => (int) $office->id,
            'inbox_id' => (int) $inbox->id,
            'gateway_event_id' => 'gateway-purge-0001',
            'sha256' => hash('sha256', 'blob a remover'),
        ];
        $stored = app(CommunicationMediaStore::class)->putStream(
            Utils::streamFor('blob a remover'),
            $metadata,
        );
        CommunicationAttachment::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'message_id' => $message->id,
            'object_id' => $stored['object_id'],
            'original_name_encrypted' => 'privado.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => $stored['size_bytes'],
            'sha256' => $stored['sha256'],
            'storage_context' => $metadata,
        ]);
        $this->authenticate($admin);

        $export = $this->get('/api/v1/communication/contacts/'.$contact->id.'/export')->assertOk();
        $this->assertStringContainsString('conteúdo pessoal', $export->streamedContent());
        $this->deleteJson('/api/v1/communication/contacts/'.$contact->id.'/personal-data')
            ->assertOk()
            ->assertJsonPath('data.deleted_blobs', 1);

        $this->assertFalse(app(CommunicationMediaStore::class)->exists($stored['object_id']));
        $this->assertNull($message->refresh()->body_encrypted);
        $this->assertNotNull($message->purged_at);
        $this->assertSame(ConversationStatus::Resolved, $conversation->refresh()->status);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $conversation->tombstone_digest);
        $this->assertSame('[removido]', $conversation->identity->refresh()->address_masked);
        $this->assertDatabaseHas('communication_events', ['type' => 'CONTACT_PURGED']);
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();
    }

    private function inbox(Office $office, string $name): CommunicationInbox
    {
        return CommunicationInbox::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'name' => $name,
            'session_id' => 'session-'.Str::ulid(),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
        ]);
    }

    private function member(CommunicationInbox $inbox, User $user): void
    {
        $membership = OfficeMembership::query()->withoutGlobalScopes()
            ->where('office_id', $inbox->office_id)
            ->where('user_id', $user->id)
            ->firstOrFail();
        CommunicationInboxMember::query()->withoutGlobalScopes()->create([
            'office_id' => $inbox->office_id,
            'inbox_id' => $inbox->id,
            'office_membership_id' => $membership->id,
            'is_active' => true,
        ]);
    }

    private function conversation(Office $office, CommunicationInbox $inbox, string $address): CommunicationConversation
    {
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'name' => 'Contato '.substr($address, -4),
            'is_active' => true,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::Whatsapp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => '***'.substr($address, -4),
            'is_active' => true,
        ]);

        return CommunicationConversation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ]);
    }

    private function message(
        Office $office,
        CommunicationInbox $inbox,
        CommunicationConversation $conversation,
        string $body,
    ): CommunicationMessage {
        return CommunicationMessage::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
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
        Office $office,
        CommunicationInbox $inbox,
        CommunicationConversation $conversation,
        ?CommunicationMessage $message,
        string $type,
    ): CommunicationEvent {
        return CommunicationEvent::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
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
