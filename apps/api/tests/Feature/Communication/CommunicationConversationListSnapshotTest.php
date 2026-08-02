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
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationConversationUnreadMessage;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxMember;
use App\Models\CommunicationMessage;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CommunicationConversationListSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'communication.enabled' => true,
            'communication.conversation_list_snapshot.cache_store' => 'array',
            'communication.conversation_list_snapshot.max_ids' => 10_000,
            'communication.conversation_list_snapshot.ttl_seconds' => 28_800,
            'communication.conversation_list_snapshot.rate_limit_per_minute' => 100,
        ]);
        Cache::store('array')->flush();
    }

    public function test_snapshot_preserves_membership_order_and_live_projections_across_pages(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $base = now()->subDay()->startOfSecond();
        $conversations = [];
        $messages = [];

        foreach (range(1, 5) as $position) {
            $conversation = $this->conversation($tenant, $inbox, '+55119999100'.str_pad((string) $position, 2, '0', STR_PAD_LEFT));
            $conversation->forceFill([
                'created_at' => $base->copy()->addMinutes($position),
                'last_message_at' => $base->copy()->addMinutes($position),
            ])->save();
            $message = $this->message($tenant, $inbox, $conversation, 'inbound-'.$position);
            $this->seedUnread($conversation, $message);
            $conversations[] = $conversation;
            $messages[] = $message;
        }

        $this->authenticate($operator);
        $query = 'status=OPEN&unread=1&sort_by=created_asc&per_page=2';
        $first = $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot=true')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5);
        $token = (string) $first->json('meta.snapshot_token');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
        $this->assertNotEmpty($first->json('meta.snapshot_expires_at'));
        $this->assertSame(
            [(int) $conversations[0]->id, (int) $conversations[1]->id],
            $this->responseIds($first->json('data')),
        );

        foreach ([0, 1] as $index) {
            $this->putJson('/api/v1/communication/conversations/'.$conversations[$index]->id.'/read-state', [
                'state' => 'READ',
                'through_message_id' => $messages[$index]->id,
            ])->assertOk()->assertJsonPath('data.unread_count', 0);
        }
        $conversations[0]->forceFill([
            'status' => ConversationStatus::Pending,
            'last_message_at' => now()->addDay(),
        ])->save();

        $newConversation = $this->conversation($tenant, $inbox, '+5511999910099');
        $newConversation->forceFill([
            'created_at' => $base->copy()->addHours(2),
            'last_message_at' => $base->copy()->addHours(2),
        ])->save();
        $this->seedUnread(
            $newConversation,
            $this->message($tenant, $inbox, $newConversation, 'inbound-nova'),
        );

        $snapshotPages = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->getJson(
                '/api/v1/communication/conversations?'.$query.'&page='.$page.'&snapshot_token='.$token,
            )->assertOk()->assertJsonPath('meta.total', 5);
            array_push($snapshotPages, ...$this->responseIds($response->json('data')));

            if ($page === 1) {
                $response
                    ->assertJsonPath('data.0.unread_count', 0)
                    ->assertJsonPath('data.0.status', 'PENDING')
                    ->assertJsonPath('data.1.unread_count', 0);
            }
        }

        $expectedSnapshotIds = array_map(
            static fn (CommunicationConversation $conversation): int => (int) $conversation->id,
            $conversations,
        );
        $this->assertSame($expectedSnapshotIds, $snapshotPages);
        $this->assertCount(count($snapshotPages), array_unique($snapshotPages));
        $this->assertNotContains((int) $newConversation->id, $snapshotPages);

        $renewed = $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 4);
        $renewedIds = $this->responseIds($renewed->json('data'));
        $this->assertNotContains((int) $conversations[0]->id, $renewedIds);
        $this->assertNotContains((int) $conversations[1]->id, $renewedIds);
        $this->assertSame(
            [(int) $conversations[2]->id, (int) $conversations[3]->id],
            $renewedIds,
        );
        $renewedToken = (string) $renewed->json('meta.snapshot_token');
        $renewedSecondPage = $this->getJson(
            '/api/v1/communication/conversations?'.$query.'&page=2&snapshot_token='.$renewedToken,
        )->assertOk();
        $this->assertSame(
            [(int) $conversations[4]->id, (int) $newConversation->id],
            $this->responseIds($renewedSecondPage->json('data')),
        );
    }

    public function test_snapshot_token_is_bound_to_actor_tenant_filters_and_per_page(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $otherActor = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $this->member($inbox, $otherActor);
        $conversation = $this->conversation($tenant, $inbox, '+5511999920001');
        $this->seedUnread($conversation, $this->message($tenant, $inbox, $conversation, 'inbound'));

        $this->authenticate($operator);
        $query = 'status=OPEN&unread=1&sort_by=created_asc&per_page=2';
        $token = (string) $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot=true')
            ->assertOk()
            ->json('meta.snapshot_token');

        $this->getJson('/api/v1/communication/conversations?status=OPEN&unread=1&sort_by=created_desc&per_page=2&snapshot_token='.$token)
            ->assertStatus(410)
            ->assertJsonPath('code', 'CONVERSATION_LIST_SNAPSHOT_EXPIRED');
        $this->getJson('/api/v1/communication/conversations?status=OPEN&unread=1&sort_by=created_asc&per_page=3&snapshot_token='.$token)
            ->assertStatus(410)
            ->assertJsonPath('code', 'CONVERSATION_LIST_SNAPSHOT_EXPIRED');

        $this->authenticate($otherActor);
        $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot_token='.$token)
            ->assertStatus(410)
            ->assertJsonPath('code', 'CONVERSATION_LIST_SNAPSHOT_EXPIRED');

        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignActor = User::factory()->forTenant($foreignTenant, TenantRole::TenantUser)->create();
        $foreignInbox = $this->inbox($foreignTenant, 'Estrangeira');
        $this->member($foreignInbox, $foreignActor);
        $this->authenticate($foreignActor);
        $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot_token='.$token)
            ->assertStatus(410)
            ->assertJsonPath('code', 'CONVERSATION_LIST_SNAPSHOT_EXPIRED');
    }

    public function test_snapshot_expires_when_authorization_or_visible_inboxes_change(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999930001');
        $this->seedUnread($conversation, $this->message($tenant, $inbox, $conversation, 'inbound'));
        $query = 'status=OPEN&unread=1&per_page=2';

        $this->authenticate($operator);
        $token = (string) $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot=true')
            ->assertOk()
            ->json('meta.snapshot_token');
        $membership = TenantMembership::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $operator->id)
            ->firstOrFail();
        $membership->bumpAuthorizationVersion();

        $this->authenticate($operator);
        $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot_token='.$token)
            ->assertStatus(410)
            ->assertJsonPath('code', 'CONVERSATION_LIST_SNAPSHOT_EXPIRED');

        $freshToken = (string) $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot=true')
            ->assertOk()
            ->json('meta.snapshot_token');
        CommunicationInboxMember::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('inbox_id', $inbox->id)
            ->where('tenant_membership_id', $membership->id)
            ->update(['is_active' => false]);

        $this->authenticate($operator);
        $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot_token='.$freshToken)
            ->assertStatus(410)
            ->assertJsonPath('code', 'CONVERSATION_LIST_SNAPSHOT_EXPIRED');
    }

    public function test_snapshot_reauthorizes_every_id_before_returning_a_page(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $visibleInbox = $this->inbox($tenant, 'Visível');
        $hiddenInbox = $this->inbox($tenant, 'Oculta');
        $this->member($visibleInbox, $operator);
        $first = $this->conversation($tenant, $visibleInbox, '+5511999931001');
        $second = $this->conversation($tenant, $visibleInbox, '+5511999931002');
        $this->seedUnread($first, $this->message($tenant, $visibleInbox, $first, 'primeira'));
        $this->seedUnread($second, $this->message($tenant, $visibleInbox, $second, 'segunda'));
        $query = 'status=OPEN&unread=1&sort_by=created_asc&per_page=1';

        $this->authenticate($operator);
        $token = (string) $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->json('meta.snapshot_token');
        $second->forceFill(['inbox_id' => $hiddenInbox->id])->save();

        $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot_token='.$token)
            ->assertStatus(410)
            ->assertJsonPath('code', 'CONVERSATION_LIST_SNAPSHOT_EXPIRED');
    }

    public function test_expired_malformed_missing_and_unresolvable_snapshots_fail_closed(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999940001');
        $this->seedUnread($conversation, $this->message($tenant, $inbox, $conversation, 'inbound'));
        $query = 'status=OPEN&unread=1&per_page=2';

        config(['communication.conversation_list_snapshot.ttl_seconds' => 1]);
        $this->authenticate($operator);
        $token = (string) $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot=true')
            ->assertOk()
            ->json('meta.snapshot_token');
        $this->travel(2)->seconds();
        $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot_token='.$token)
            ->assertStatus(410)
            ->assertJsonPath('code', 'CONVERSATION_LIST_SNAPSHOT_EXPIRED');
        $this->travelBack();

        foreach (['not-a-token', str_repeat('a', 64)] as $invalidToken) {
            $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot_token='.$invalidToken)
                ->assertStatus(410)
                ->assertJsonPath('code', 'CONVERSATION_LIST_SNAPSHOT_EXPIRED');
        }

        config(['communication.conversation_list_snapshot.ttl_seconds' => 28_800]);
        $freshToken = (string) $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot=true')
            ->assertOk()
            ->json('meta.snapshot_token');
        $survivor = $this->conversation($tenant, $inbox, '+5511999940002');
        $conversation->forceFill([
            'status' => ConversationStatus::Resolved,
            'resolved_at' => now(),
            'merged_into_conversation_id' => $survivor->id,
        ])->save();
        $this->getJson('/api/v1/communication/conversations?'.$query.'&snapshot_token='.$freshToken)
            ->assertStatus(410)
            ->assertJsonPath('code', 'CONVERSATION_LIST_SNAPSHOT_EXPIRED');
    }

    public function test_snapshot_limit_and_cache_unavailability_have_stable_errors(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        foreach (range(1, 3) as $position) {
            $conversation = $this->conversation($tenant, $inbox, '+55119999500'.$position);
            $this->seedUnread($conversation, $this->message($tenant, $inbox, $conversation, 'inbound-'.$position));
        }
        $this->authenticate($operator);

        config(['communication.conversation_list_snapshot.max_ids' => 3]);
        $this->getJson('/api/v1/communication/conversations?status=OPEN&unread=1&snapshot=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
        Cache::store('array')->flush();

        config(['communication.conversation_list_snapshot.max_ids' => 2]);
        $this->getJson('/api/v1/communication/conversations?status=OPEN&unread=1&snapshot=true')
            ->assertStatus(422)
            ->assertJsonPath('code', 'CONVERSATION_LIST_SNAPSHOT_TOO_LARGE');
        $this->assertSame([], $this->snapshotCachePayloads());

        config([
            'communication.conversation_list_snapshot.max_ids' => 10_000,
            'communication.conversation_list_snapshot.cache_store' => 'null',
        ]);
        $this->getJson('/api/v1/communication/conversations?status=OPEN&unread=1&snapshot=true')
            ->assertStatus(503)
            ->assertJsonPath('code', 'CONVERSATION_LIST_SNAPSHOT_UNAVAILABLE');
    }

    public function test_snapshot_validation_live_response_payload_and_conditional_limiter(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999960001');
        $this->seedUnread($conversation, $this->message($tenant, $inbox, $conversation, 'segredo-inbound'));
        $this->authenticate($operator);

        $liveResponse = $this->getJson('/api/v1/communication/conversations?status=OPEN&unread=1')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total']]);
        $this->assertArrayNotHasKey('snapshot_token', $liveResponse->json('meta'));
        $this->assertArrayNotHasKey('snapshot_expires_at', $liveResponse->json('meta'));

        $this->getJson('/api/v1/communication/conversations?snapshot=true')
            ->assertUnprocessable();
        $this->getJson('/api/v1/communication/conversations?unread=1&page=2&snapshot=true')
            ->assertUnprocessable();
        $this->getJson('/api/v1/communication/conversations?snapshot_token='.str_repeat('a', 64))
            ->assertUnprocessable();

        $snapshot = $this->getJson('/api/v1/communication/conversations?status=OPEN&unread=1&q=segredo&snapshot=true')
            ->assertOk();
        $payloads = $this->snapshotCachePayloads();
        $this->assertCount(1, $payloads);
        $payload = $payloads[0];
        $this->assertSame([
            'schema_version',
            'tenant_id',
            'actor_id',
            'query_hash',
            'access_hash',
            'inboxes_hash',
            'conversation_ids',
            'created_at',
            'expires_at',
        ], array_keys($payload));
        $this->assertStringNotContainsString('segredo', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertSame(
            [(int) $conversation->id],
            $this->responseIds($snapshot->json('data')),
        );

        config(['communication.conversation_list_snapshot.rate_limit_per_minute' => 1]);
        $this->getJson('/api/v1/communication/conversations?status=OPEN&unread=1&snapshot=true')
            ->assertStatus(429);
        $this->getJson('/api/v1/communication/conversations?status=OPEN&unread=1')
            ->assertOk();
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }

    /** @return list<array<string, mixed>> */
    private function snapshotCachePayloads(): array
    {
        return collect(Cache::store('array')->getStore()->all())
            ->pluck('value')
            ->filter(static fn (mixed $value): bool => is_array($value)
                && array_key_exists('schema_version', $value)
                && array_key_exists('conversation_ids', $value))
            ->values()
            ->all();
    }

    /** @param list<array<string, mixed>> $data @return list<int> */
    private function responseIds(array $data): array
    {
        return array_map(static fn (array $item): int => (int) $item['id'], $data);
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
    ): CommunicationConversation {
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
