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
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationConversationReadState;
use App\Models\CommunicationConversationUnreadMessage;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxIdentityProfile;
use App\Models\CommunicationInboxMember;
use App\Models\CommunicationIdentityLink;
use App\Models\CommunicationMessage;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Communication\Contact\CommunicationInboxIdentityProfileMerger;
use App\Services\Communication\Conversation\CommunicationConversationReadStateService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CommunicationConversationWorkspaceTest extends TestCase
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
        ]);
    }

    public function test_list_exposes_display_title_preview_and_unread_filter(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999992001', provisional: true);
        $message = $this->message($tenant, $inbox, $conversation, 'Olá workspace');
        $this->seedUnread($conversation, $message);
        $this->seedUnread($conversation, $this->message($tenant, $inbox, $conversation, 'segunda'));

        CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $conversation->identity_id,
            'push_name' => 'Push Cliente',
            'business_name' => 'Empresa XPTO',
        ]);

        $this->authenticate($operator);
        $response = $this->getJson('/api/v1/communication/conversations?unread=1');
        $response->assertOk();
        $item = $response->json('data.0');
        $this->assertNotNull($item);
        $this->assertSame(2, $item['unread_count']);
        $this->assertSame($message->id, $item['first_unread_message_id']);
        $this->assertSame('Empresa XPTO', $item['display_title']);
        $this->assertSame('WHATSAPP_BUSINESS', $item['display_title_source']);
        $this->assertIsArray($item['preview']);
        $this->assertSame('text', $item['preview']['kind']);
    }

    public function test_mark_read_and_unread_are_local_and_idempotent(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999992002');
        $message = $this->message($tenant, $inbox, $conversation, 'Mensagem inbound');
        $this->seedUnread($conversation, $message);

        $this->authenticate($operator);

        $this->putJson('/api/v1/communication/conversations/'.$conversation->id.'/read-state', [
            'state' => 'READ',
            'through_message_id' => $message->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.first_unread_message_id', null)
            ->assertJsonPath('data.read_state.version', 1);

        $this->putJson('/api/v1/communication/conversations/'.$conversation->id.'/read-state', [
            'state' => 'READ',
            'through_message_id' => $message->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.read_state.version', 1);

        $this->putJson('/api/v1/communication/conversations/'.$conversation->id.'/read-state', [
            'state' => 'UNREAD',
            'expected_version' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.first_unread_message_id', $message->id)
            ->assertJsonPath('data.read_state.version', 2);

        $this->putJson('/api/v1/communication/conversations/'.$conversation->id.'/read-state', [
            'state' => 'UNREAD',
            'expected_version' => 1,
        ])->assertStatus(409)->assertJsonPath('code', 'READ_STATE_VERSION_CONFLICT');

        $this->assertNull($message->fresh()->read_at);
    }

    public function test_timeline_cursor_supports_first_unread_anchor(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999992003');
        $this->message($tenant, $inbox, $conversation, 'primeira');
        $second = $this->message($tenant, $inbox, $conversation, 'segunda');
        $this->seedUnread($conversation, $second);

        $this->authenticate($operator);
        $response = $this->getJson(
            '/api/v1/communication/conversations/'.$conversation->id.'/messages?anchor=first_unread&limit=10'
        );
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($second->id, $ids);
        $this->assertSame($second->id, $response->json('meta.first_unread_message_id'));
    }

    public function test_profile_observations_do_not_overwrite_curated_name(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999992004');
        $contact = $conversation->identity->contact;
        $contact->forceFill([
            'name' => 'Nome Curado',
            'is_provisional' => false,
        ])->save();

        app(CommunicationInboxIdentityProfileMerger::class)->merge(
            $inbox,
            $conversation->identity,
            ['push_name' => 'Push Novo'],
            now(),
            'profile-event-00000001',
        );

        $this->assertSame('Nome Curado', $contact->fresh()->name);
        $observation = CommunicationInboxIdentityProfile::query()
            ->withoutGlobalScopes()
            ->where('inbox_id', $inbox->id)
            ->where('identity_id', $conversation->identity_id)
            ->first();
        $this->assertSame('Push Novo', $observation?->push_name);
    }

    public function test_display_name_precedence_is_inbox_scoped_and_skips_ambiguous_client_contacts(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $firstInbox = $this->inbox($tenant, 'Primeira');
        $secondInbox = $this->inbox($tenant, 'Segunda');
        $this->member($firstInbox, $operator);
        $this->member($secondInbox, $operator);
        $firstConversation = $this->conversation($tenant, $firstInbox, '+5511999992010', provisional: true);
        $secondConversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $secondInbox->id,
            'identity_id' => $firstConversation->identity_id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ]);
        $merger = app(CommunicationInboxIdentityProfileMerger::class);
        $merger->merge(
            $firstInbox,
            $firstConversation->identity,
            ['address_book_full_name' => 'Nome da primeira agenda'],
            now(),
            'profile-first-inbox-0001',
        );
        $merger->merge(
            $secondInbox,
            $firstConversation->identity,
            ['address_book_full_name' => 'Nome da segunda agenda'],
            now(),
            'profile-second-inbox-0001',
        );
        $this->authenticate($operator);

        $this->getJson('/api/v1/communication/conversations?inbox_id='.$firstInbox->id)
            ->assertOk()
            ->assertJsonPath('data.0.display_name', 'Nome da primeira agenda');
        $this->getJson('/api/v1/communication/conversations?inbox_id='.$secondInbox->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $secondConversation->id)
            ->assertJsonPath('data.0.display_name', 'Nome da segunda agenda');

        $firstConversation->identity->contact->forceFill([
            'name' => 'Nome manual',
            'is_provisional' => false,
        ])->save();
        $this->getJson('/api/v1/communication/conversations?inbox_id='.$firstInbox->id)
            ->assertOk()
            ->assertJsonPath('data.0.display_name', 'Nome manual')
            ->assertJsonPath('data.0.display_name_source', 'MANUAL_CONTACT');

        $firstConversation->identity->contact->forceFill([
            'name' => null,
            'is_provisional' => true,
        ])->save();
        $firstClient = Client::factory()->forTenant($tenant)->create();
        $firstClientContact = ClientContact::factory()->forClient($firstClient)->create(['name' => 'Pessoa vinculada']);
        CommunicationIdentityLink::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'identity_id' => $firstConversation->identity_id,
            'client_id' => $firstClient->id,
            'client_contact_id' => $firstClientContact->id,
        ]);
        $this->getJson('/api/v1/communication/conversations?inbox_id='.$firstInbox->id)
            ->assertOk()
            ->assertJsonPath('data.0.display_name', 'Pessoa vinculada')
            ->assertJsonPath('data.0.display_name_source', 'CLIENT_CONTACT');

        $secondClient = Client::factory()->forTenant($tenant)->create();
        $secondClientContact = ClientContact::factory()->forClient($secondClient)->create(['name' => 'Outra pessoa']);
        CommunicationIdentityLink::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'identity_id' => $firstConversation->identity_id,
            'client_id' => $secondClient->id,
            'client_contact_id' => $secondClientContact->id,
        ]);
        $this->getJson('/api/v1/communication/conversations?inbox_id='.$firstInbox->id)
            ->assertOk()
            ->assertJsonPath('data.0.display_name', 'Nome da primeira agenda')
            ->assertJsonPath('data.0.display_name_source', 'WHATSAPP_ADDRESS_BOOK');
    }

    public function test_profile_merge_preserves_missing_fields_orders_events_and_requires_explicit_clear(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant, 'Perfis');
        $conversation = $this->conversation($tenant, $inbox, '+5511999992011', provisional: true);
        $merger = app(CommunicationInboxIdentityProfileMerger::class);
        $base = now()->subMinute();

        $merger->merge($inbox, $conversation->identity, [
            'business_name' => 'Empresa preservada',
            'push_name' => 'Push inicial',
        ], $base, 'profile-order-0002');
        $merger->merge($inbox, $conversation->identity, [
            'push_name' => 'Push antigo',
        ], $base->copy()->subSecond(), 'profile-order-0001');
        $profile = $merger->merge($inbox, $conversation->identity, [
            'address_book_first_name' => 'Maria',
        ], $base->copy()->addSecond(), 'profile-order-0003');
        $this->assertSame('Empresa preservada', $profile->business_name);
        $this->assertSame('Push inicial', $profile->push_name);
        $this->assertSame('Maria', $profile->address_book_first_name);

        $profile = $merger->merge(
            $inbox,
            $conversation->identity,
            [],
            $base->copy()->addSeconds(2),
            'profile-order-0004',
            ['push_name'],
        );
        $this->assertNull($profile->push_name);
        $this->assertSame('Empresa preservada', $profile->business_name);
        $this->assertContains('push_name', $profile->cleared_fields);
    }

    public function test_read_state_merge_moves_unread_rows_to_survivor(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant, 'Atendimento');
        $survivor = $this->conversation($tenant, $inbox, '+5511999992005');
        $messageA = $this->message($tenant, $inbox, $survivor, 'inbound-a');
        $this->seedUnread($survivor, $messageA);

        $donor = $this->conversation($tenant, $inbox, '+5511999992006');
        $messageB = $this->message($tenant, $inbox, $donor, 'inbound-b');
        $messageC = $this->message($tenant, $inbox, $donor, 'inbound-c');
        $this->seedUnread($donor, $messageB);
        $this->seedUnread($donor, $messageC);
        CommunicationMessage::query()->withoutGlobalScopes()
            ->whereIn('id', [$messageB->id, $messageC->id])
            ->update(['conversation_id' => $survivor->id]);

        app(CommunicationConversationReadStateService::class)
            ->mergeFragments($survivor->fresh(), [$donor->fresh()]);

        $snapshot = app(CommunicationConversationReadStateService::class)->snapshot($survivor->fresh());
        $this->assertSame(3, $snapshot['unread_count']);
        $this->assertContains($snapshot['first_unread_message_id'], [$messageA->id, $messageB->id, $messageC->id]);
        $this->assertSame(
            0,
            CommunicationConversationUnreadMessage::query()
                ->withoutGlobalScopes()
                ->where('conversation_id', $donor->id)
                ->count(),
        );
    }

    public function test_read_state_writer_receiving_donor_id_mutates_survivor(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $survivor = $this->conversation($tenant, $inbox, '+5511999992012');
        $donor = $this->conversation($tenant, $inbox, '+5511999992013');
        $donor->forceFill([
            'status' => ConversationStatus::Resolved,
            'resolved_at' => now(),
            'merged_into_conversation_id' => $survivor->id,
        ])->save();
        $message = $this->message($tenant, $inbox, $survivor, 'inbound survivor');
        $this->seedUnread($survivor, $message);
        $this->authenticate($operator);

        $this->putJson('/api/v1/communication/conversations/'.$donor->id.'/read-state', [
            'state' => 'READ',
            'through_message_id' => $message->id,
        ])->assertOk()
            ->assertJsonPath('data.id', $survivor->id)
            ->assertJsonPath('data.unread_count', 0);
        $this->assertDatabaseMissing('communication_conversation_unread_messages', [
            'conversation_id' => $survivor->id,
            'message_id' => $message->id,
        ]);
    }

    public function test_read_removes_unread_inserted_below_existing_watermark(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999992007');
        $inbound = $this->message($tenant, $inbox, $conversation, 'inbound antiga');
        $outbound = $this->message(
            $tenant,
            $inbox,
            $conversation,
            'outbound posterior',
            MessageDirection::Outbound,
        );
        $this->authenticate($operator);

        $this->putJson('/api/v1/communication/conversations/'.$conversation->id.'/read-state', [
            'state' => 'READ',
            'through_message_id' => $outbound->id,
        ])->assertOk();
        $version = (int) CommunicationConversationReadState::query()
            ->withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->value('version');
        $this->putJson('/api/v1/communication/conversations/'.$conversation->id.'/read-state', [
            'state' => 'UNREAD',
            'expected_version' => $version,
        ])->assertOk()->assertJsonPath('data.first_unread_message_id', $inbound->id);

        $this->putJson('/api/v1/communication/conversations/'.$conversation->id.'/read-state', [
            'state' => 'READ',
            'through_message_id' => $inbound->id,
        ])->assertOk()
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.read_state.last_read_through_message_id', $outbound->id);
    }

    public function test_timeline_cursor_preserves_microseconds_and_is_bound_to_conversation(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999992008');
        $other = $this->conversation($tenant, $inbox, '+5511999992009');
        $base = now()->startOfSecond();
        $first = $this->message($tenant, $inbox, $conversation, 'primeira');
        $second = $this->message($tenant, $inbox, $conversation, 'segunda');
        $third = $this->message($tenant, $inbox, $conversation, 'terceira');
        $first->forceFill(['occurred_at' => $base->copy()->addMicroseconds(100)])->save();
        $second->forceFill(['occurred_at' => $base->copy()->addMicroseconds(200)])->save();
        $third->forceFill(['occurred_at' => $base->copy()->addMicroseconds(300)])->save();
        $this->authenticate($operator);

        $latest = $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/messages?limit=1')
            ->assertOk();
        $this->assertSame([$third->id], collect($latest->json('data'))->pluck('id')->all());
        $olderCursor = (string) $latest->json('meta.older_cursor');
        $older = $this->getJson('/api/v1/communication/conversations/'.$conversation->id.'/messages?limit=1&cursor='.urlencode($olderCursor))
            ->assertOk();
        $this->assertSame([$second->id], collect($older->json('data'))->pluck('id')->all());
        $this->getJson('/api/v1/communication/conversations/'.$other->id.'/messages?limit=1&cursor='.urlencode($olderCursor))
            ->assertUnprocessable();
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }

    private function seedUnread(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
    ): void {
        CommunicationConversationUnreadMessage::query()->withoutGlobalScopes()->insertOrIgnore([[
            'tenant_id' => $conversation->tenant_id,
            'inbox_id' => $conversation->inbox_id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
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

    private function conversation(
        Tenant $tenant,
        CommunicationInbox $inbox,
        string $address,
        bool $provisional = false,
    ): CommunicationConversation {
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $provisional ? null : 'Contato '.substr($address, -4),
            'is_provisional' => $provisional,
            'is_active' => true,
        ]);
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
        $identity->setRelation('contact', $contact);
        $conversation->setRelation('identity', $identity);

        return $conversation;
    }

    private function message(
        Tenant $tenant,
        CommunicationInbox $inbox,
        CommunicationConversation $conversation,
        string $body,
        MessageDirection $direction = MessageDirection::Inbound,
    ): CommunicationMessage {
        return CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $conversation->identity_id,
            'direction' => $direction,
            'kind' => MessageKind::Text,
            'source' => MessageSource::Gateway,
            'status' => MessageStatus::Delivered,
            'body_encrypted' => $body,
            'provider_message_id' => 'provider-'.strtolower((string) Str::ulid()),
            'content_digest' => hash('sha256', $body),
            'occurred_at' => now(),
        ]);
    }
}
