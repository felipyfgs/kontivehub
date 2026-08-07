<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\CommunicationChannel;
use App\Enums\TenantRole;
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
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CommunicationSingularOutboundFamiliesTest extends TestCase
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
            'communication.media.disk_root' => sys_get_temp_dir().'/communication-singular-families-'.Str::ulid(),
            'communication.outbound_features.contacts_array' => true,
            'communication.outbound_features.gif' => true,
            'communication.outbound_features.ptv' => true,
            'communication.outbound_features.event' => true,
            'communication.outbound_features.view_once' => true,
            'communication.outbound_builders.contacts_array' => true,
            'communication.outbound_builders.gif' => true,
            'communication.outbound_builders.ptv' => true,
            'communication.outbound_builders.event' => true,
            'communication.outbound_builders.view_once' => true,
        ]);
    }

    public function test_prior_contact_and_additive_contacts_array_keep_their_distinct_provider_shapes(): void
    {
        [$conversation] = $this->context();

        $this->postJson($this->messagesUrl($conversation), [
            'kind' => 'CONTACT',
            'contact' => $this->contactPayload('Um contato'),
            'idempotency_key' => 'singular-contact-0001',
        ])->assertAccepted()->assertJsonPath('data.kind', 'CONTACT');
        $prior = CommunicationMessage::query()->latest('id')->firstOrFail();
        $this->assertSame('contactMessage', $prior->provider_type);
        $this->assertCount(1, $prior->content_encrypted['contacts']);

        $this->postJson($this->messagesUrl($conversation), [
            'kind' => 'CONTACT',
            'contacts' => [$this->contactPayload('Primeiro'), $this->contactPayload('Segundo')],
            'idempotency_key' => 'singular-contacts-0001',
        ])->assertAccepted()->assertJsonPath('data.kind', 'CONTACT');
        $array = CommunicationMessage::query()->latest('id')->firstOrFail();
        $this->assertSame('contactsArrayMessage', $array->provider_type);
        $this->assertSame(['Primeiro', 'Segundo'], array_column($array->content_encrypted['contacts'], 'display_name'));
        $this->assertSame($array->id, CommunicationOutboxEntry::query()->latest('id')->value('message_id'));

        $this->postJson($this->messagesUrl($conversation), [
            'kind' => 'CONTACT',
            'contacts' => array_fill(0, 11, $this->contactPayload('Além do limite')),
            'idempotency_key' => 'singular-contacts-limit-0001',
        ])->assertUnprocessable();
    }

    public function test_event_and_media_variants_are_persisted_with_allowlisted_semantics(): void
    {
        [$conversation] = $this->context();
        $this->postJson($this->messagesUrl($conversation), [
            'kind' => 'EVENT',
            'event' => [
                'title' => 'Reunião de fechamento',
                'description' => 'Alinhamento mensal',
                'start_at' => '2026-08-04T14:00:00+00:00',
                'end_at' => '2026-08-04T15:00:00+00:00',
                'timezone' => 'America/Sao_Paulo',
                'location_name' => 'Sala virtual',
                'participation_enabled' => true,
            ],
            'idempotency_key' => 'singular-event-0001',
        ])->assertAccepted()->assertJsonPath('data.kind', 'EVENT');
        $event = CommunicationMessage::query()->latest('id')->firstOrFail();
        $this->assertSame('eventMessage', $event->provider_type);
        $this->assertSame('Reunião de fechamento', $event->content_encrypted['event']['title']);

        $this->post($this->messagesUrl($conversation), [
            'kind' => 'VIDEO',
            'gif' => true,
            'file' => $this->mp4('animacao.mp4'),
            'idempotency_key' => 'singular-gif-0001',
        ], ['Accept' => 'application/json'])->assertAccepted();
        $gif = CommunicationMessage::query()->latest('id')->firstOrFail();
        $this->assertTrue($gif->content_encrypted['gif']);

        $this->post($this->messagesUrl($conversation), [
            'kind' => 'VIDEO',
            'ptv' => true,
            'file' => $this->mp4('circular.mp4'),
            'idempotency_key' => 'singular-ptv-0001',
        ], ['Accept' => 'application/json'])->assertAccepted();
        $ptv = CommunicationMessage::query()->latest('id')->firstOrFail();
        $this->assertSame('ptvMessage', $ptv->provider_type);
        $this->assertSame(['ptv'], $ptv->content_encrypted['variants']);

        $this->post($this->messagesUrl($conversation), [
            'kind' => 'IMAGE',
            'view_once' => true,
            'file' => UploadedFile::fake()->createWithContent(
                'privada.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
            ),
            'idempotency_key' => 'singular-view-once-0001',
        ], ['Accept' => 'application/json'])->assertAccepted();
        $viewOnce = CommunicationMessage::query()->latest('id')->firstOrFail();
        $this->assertSame('imageMessage', $viewOnce->provider_type);
        $this->assertTrue($viewOnce->metadata['view_once']);
    }

    public function test_disabled_or_incompatible_new_variants_are_rejected_before_storage(): void
    {
        [$conversation] = $this->context();
        config(['communication.outbound_features.event' => false]);
        $this->postJson($this->messagesUrl($conversation), [
            'kind' => 'EVENT',
            'event' => ['title' => 'Bloqueado', 'start_at' => '2026-08-04T14:00:00+00:00', 'timezone' => 'UTC'],
            'idempotency_key' => 'singular-event-blocked-0001',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('communication_messages', 0);
        $this->assertDatabaseCount('communication_outbox_entries', 0);

        config(['communication.outbound_features.ptv' => false]);
        $this->post($this->messagesUrl($conversation), [
            'kind' => 'VIDEO',
            'ptv' => true,
            'file' => $this->mp4('ptv-bloqueado.mp4'),
            'idempotency_key' => 'singular-ptv-blocked-0001',
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('communication_attachments', 0);

        config(['communication.outbound_features.event' => true, 'communication.outbound_features.ptv' => true]);
        $this->postJson($this->messagesUrl($conversation), [
            'kind' => 'EVENT',
            'event' => [
                'title' => 'Campo não permitido',
                'start_at' => '2026-08-04T14:00:00+00:00',
                'timezone' => 'UTC',
                'protobuf_escape_hatch' => true,
            ],
            'idempotency_key' => 'singular-event-invalid-0001',
        ])->assertUnprocessable();
        $this->post($this->messagesUrl($conversation), [
            'kind' => 'VIDEO',
            'gif' => true,
            'ptv' => true,
            'file' => $this->mp4('incompativel.mp4'),
            'idempotency_key' => 'singular-variant-blocked-0001',
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('communication_messages', 0);
        $this->assertDatabaseCount('communication_attachments', 0);
        $this->assertDatabaseCount('communication_outbox_entries', 0);
    }

    public function test_idempotency_digest_conflicts_when_the_same_media_key_changes_variant(): void
    {
        [$conversation] = $this->context();
        $file = $this->mp4('replay.mp4');
        $payload = ['kind' => 'VIDEO', 'file' => $file, 'idempotency_key' => 'singular-variant-replay-0001'];

        $this->post($this->messagesUrl($conversation), [...$payload, 'gif' => true], ['Accept' => 'application/json'])
            ->assertAccepted();
        $this->post($this->messagesUrl($conversation), [...$payload, 'ptv' => true], ['Accept' => 'application/json'])
            ->assertConflict()
            ->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('communication_messages', 1);
        $this->assertDatabaseCount('communication_outbox_entries', 1);
    }

    /** @return array{CommunicationConversation, User} */
    private function context(): array
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $user = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Inbox', 'session_id' => 'session-'.Str::ulid(),
            'address_encrypted' => '+5511000000001', 'address_hash' => hash('sha256', '+5511000000001'),
            'address_masked' => '***0001', 'status' => InboxStatus::Connected, 'is_enabled' => true,
        ]);
        $membership = TenantMembership::query()->withoutGlobalScopes()->where('user_id', $user->id)->sole();
        CommunicationInboxMember::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'inbox_id' => $inbox->id, 'tenant_membership_id' => $membership->id, 'is_active' => true,
        ]);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Destino', 'is_provisional' => false, 'is_active' => true,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'channel' => CommunicationChannel::WhatsApp,
            'address_encrypted' => '+5511999990001', 'address_hash' => hash('sha256', '+5511999990001'),
            'address_masked' => '***0001', 'is_active' => true,
        ]);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'inbox_id' => $inbox->id, 'identity_id' => $identity->id,
            'status' => ConversationStatus::Open, 'last_message_at' => now(),
        ]);
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();

        return [$conversation, $user];
    }

    private function mp4(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2",
        )->mimeType('video/mp4');
    }

    /** @return array{display_name:string,vcard:string} */
    private function contactPayload(string $name): array
    {
        return ['display_name' => $name, 'vcard' => "BEGIN:VCARD\nVERSION:3.0\nFN:{$name}\nTEL:+5511999990001\nEND:VCARD"];
    }

    private function messagesUrl(CommunicationConversation $conversation): string
    {
        return '/api/v1/communication/conversations/'.$conversation->id.'/messages';
    }
}
