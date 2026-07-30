<?php

namespace Tests\Feature\Communication;

use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayCommandReceipt;
use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\GatewayQueryType;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Exceptions\CommunicationTransportException;
use App\Jobs\Communication\RefreshCommunicationProfilePictureJob;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxMember;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Services\Communication\Outbox\CommunicationOutboxDispatcher;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

final class CommunicationGatewayActionApiTest extends TestCase
{
    use RefreshDatabase;

    private ActionApiTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
        ]);
        $this->transport = new ActionApiTransport;
        $this->app->instance(CommunicationTransport::class, $this->transport);
    }

    public function test_member_with_reply_can_enqueue_typed_conversation_actions_from_domain_ids(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant);
        $this->member($inbox, $operator);
        [$conversation, $inbound, $outbound, $poll] = $this->conversation($tenant, $inbox);
        $this->authenticate($operator);
        $base = '/api/v1/communication/conversations/'.$conversation->id;

        $edit = $this->putJson($base.'/messages/'.$outbound->id.'/edit', ['text' => 'Texto corrigido'])
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::EditMessage->value);
        $this->deleteJson($base.'/messages/'.$outbound->id)
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::RevokeMessage->value);
        $this->putJson($base.'/messages/'.$inbound->id.'/reaction', ['emoji' => null])
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::ReactMessage->value);
        $this->postJson($base.'/messages/'.$poll->id.'/poll-votes', ['option_names' => ['Sim']])
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::VotePoll->value);
        $this->postJson($base.'/messages/'.$inbound->id.'/receipts', ['receipt' => 'READ'])
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::MarkMessage->value);
        $this->postJson($base.'/presence/subscribe')
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::SubscribePresence->value);
        $this->putJson($base.'/presence', ['presence' => 'RECORDING', 'media' => 'AUDIO'])
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::SetChatPresence->value);
        $this->putJson($base.'/disappearing', ['timer_seconds' => 86400])
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::SetChatDisappearing->value);
        $this->putJson($base.'/state', ['action' => 'STAR', 'value' => true, 'message_id' => $inbound->id])
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::UpdateChatState->value);

        $this->assertDatabaseCount('communication_outbox_entries', 9);
        $reaction = CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->where('type', GatewayCommandType::ReactMessage->value)->firstOrFail();
        $this->assertSame('+5511999997001', $reaction->payload_encrypted['to']);
        $this->assertSame('provider-inbound-0001', $reaction->payload_encrypted['target_message_id']);
        $this->assertSame('', $reaction->payload_encrypted['emoji']);
        $this->assertSame('+5511999997001', $reaction->payload_encrypted['sender']);
        $this->assertNull($reaction->message_id);

        $editEntry = CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->where('command_id', $edit->json('data.command_id'))->firstOrFail();
        app(CommunicationOutboxDispatcher::class)->dispatch((int) $editEntry->id);
        $this->assertCount(1, $this->transport->commands);
        $this->assertSame($editEntry->command_id, $this->transport->commands[0]->providerMessageId);
        $this->assertSame('provider-outbound-0001', $this->transport->commands[0]->payload['target_message_id']);
    }

    public function test_history_and_recovery_apply_manage_and_reply_boundaries(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $inbox = $this->inbox($tenant);
        $this->member($inbox, $operator);
        [$conversation, $inbound, $outbound] = $this->conversation($tenant, $inbox);
        foreach ([$inbound, $outbound] as $recoverable) {
            $recoverable->forceFill([
                'kind' => MessageKind::Image,
                'metadata' => [
                    'history' => true,
                    'media_state' => 'RETRY_AVAILABLE',
                ],
            ])->save();
        }
        $base = '/api/v1/communication/conversations/'.$conversation->id.'/messages/'.$inbound->id;

        $this->authenticate($operator);
        $this->postJson($base.'/recovery', ['operation' => 'UNAVAILABLE'])
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::RequestUnavailableMessage->value);
        $inboundRetry = $this->postJson($base.'/recovery', ['operation' => 'MEDIA_RETRY'])
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::RequestMediaRetry->value);
        $this->postJson($base.'/recovery', ['operation' => 'MEDIA_RETRY'])
            ->assertStatus(202)
            ->assertJsonPath('data.command_id', $inboundRetry->json('data.command_id'));
        $firstInboundRetry = CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->where('command_id', $inboundRetry->json('data.command_id'))
            ->firstOrFail();
        $this->assertStringEndsWith(':1', (string) $firstInboundRetry->effect_key);
        $firstInboundRetry->delete();
        $secondInboundRetry = $this->postJson($base.'/recovery', ['operation' => 'MEDIA_RETRY'])
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::RequestMediaRetry->value);
        $this->assertNotSame($inboundRetry->json('data.command_id'), $secondInboundRetry->json('data.command_id'));
        $this->assertStringEndsWith(
            ':2',
            (string) CommunicationOutboxEntry::query()->withoutGlobalScopes()
                ->where('command_id', $secondInboundRetry->json('data.command_id'))
                ->value('effect_key'),
        );
        $this->assertSame(2, $inbound->refresh()->metadata['media_request_generation']);
        $this->postJson(
            '/api/v1/communication/conversations/'.$conversation->id.'/messages/'.$outbound->id.'/recovery',
            ['operation' => 'MEDIA_RETRY'],
        )->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::RequestMediaRetry->value);
        $this->postJson($base.'/history', ['count' => 20])->assertForbidden();
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/app-state/sync')->assertForbidden();
        $this->assertDatabaseCount('communication_outbox_entries', 3);
        $retries = CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->where('type', GatewayCommandType::RequestMediaRetry->value)
            ->orderBy('id')
            ->get();
        $this->assertSame(['INBOUND', 'OUTBOUND'], $retries->pluck('payload_encrypted.expected_direction')->all());
        $this->assertSame([$inbound->id, $outbound->id], $retries->pluck('message_id')->all());
        $this->assertFalse($retries->contains(fn (CommunicationOutboxEntry $entry): bool => array_key_exists('sender', $entry->payload_encrypted)));
        $this->assertFalse($retries->contains(fn (CommunicationOutboxEntry $entry): bool => array_key_exists('from_me', $entry->payload_encrypted)));

        $this->authenticate($admin);
        $this->postJson($base.'/history', ['count' => 20])
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::RequestHistorySync->value);
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/app-state/sync')
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::UpdateChatState->value);
    }

    public function test_permission_membership_and_tenant_are_rejected_before_outbox_or_query(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $member = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $notMember = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $foreignAdmin = User::factory()->forTenant($foreignTenant, TenantRole::TenantAdmin)->create();
        $replyProfile = TenantPermissionProfile::factory()->forTenant($tenant)->create();
        $replyProfile->syncPermissionKeys([
            TenantPermission::CommunicationView,
            TenantPermission::CommunicationReply,
        ]);
        $viewerProfile = TenantPermissionProfile::factory()->forTenant($tenant)->create();
        $viewerProfile->syncPermissionKeys([TenantPermission::CommunicationView]);
        TenantMembership::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('user_id', [$member->id, $notMember->id])
            ->update(['permission_profile_id' => $replyProfile->id]);
        TenantMembership::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $viewer->id)
            ->update(['permission_profile_id' => $viewerProfile->id]);
        $inbox = $this->inbox($tenant);
        $this->member($inbox, $member);
        $this->member($inbox, $viewer);
        [$conversation, $inbound] = $this->conversation($tenant, $inbox);
        $reaction = '/api/v1/communication/conversations/'.$conversation->id.'/messages/'.$inbound->id.'/reaction';

        $this->authenticate($notMember);
        $this->putJson($reaction, ['emoji' => '👍'])->assertForbidden();
        $this->authenticate($viewer);
        $this->putJson($reaction, ['emoji' => '👍'])->assertForbidden();
        $this->authenticate($member);
        $this->putJson('/api/v1/communication/inboxes/'.$inbox->id.'/privacy', [
            'name' => 'last', 'value' => 'contacts',
        ])->assertForbidden();
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/contacts/check', [
            'users' => ['+5511999997001'],
        ])->assertForbidden();

        $this->authenticate($foreignAdmin);
        $this->putJson($reaction, ['emoji' => '👍'])->assertNotFound();
        $this->getJson('/api/v1/communication/inboxes/'.$inbox->id.'/privacy')->assertNotFound();

        $this->assertDatabaseCount('communication_outbox_entries', 0);
        $this->assertCount(0, $this->transport->queries);
    }

    public function test_gateway_action_boundaries_reject_client_tenant_id(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $inbox = $this->inbox($tenant);
        [$conversation, $inbound, $outbound, $poll] = $this->conversation($tenant, $inbox);
        $identity = CommunicationIdentity::query()
            ->withoutGlobalScopes()
            ->findOrFail($conversation->identity_id);
        $this->authenticate($admin);

        $inboxBase = '/api/v1/communication/inboxes/'.$inbox->id;
        $conversationBase = '/api/v1/communication/conversations/'.$conversation->id;
        $requests = [
            ['GET', $inboxBase.'/session/status', []],
            ['POST', $inboxBase.'/session/disconnect', []],
            ['PUT', $inboxBase.'/session/passive', ['passive' => true]],
            ['POST', $inboxBase.'/session/pair-phone', ['phone' => '+5511999997001']],
            ['POST', $inboxBase.'/session/passkey/respond', [
                'id' => 'passkey-request-0001',
                'client_data_json' => 'client-data',
                'authenticator_data' => 'authenticator-data',
                'signature' => 'signature-data',
            ]],
            ['POST', $inboxBase.'/session/passkey/confirm', [
                'id' => 'passkey-request-0001',
                'confirm' => true,
            ]],
            ['PUT', $inboxBase.'/presence', ['presence' => 'AVAILABLE']],
            ['PUT', $inboxBase.'/default-disappearing', ['timer_seconds' => 0]],
            ['POST', $inboxBase.'/app-state/sync', []],
            ['POST', $inboxBase.'/app-state/mark-clean', ['timestamp' => 1784746800]],
            ['GET', $inboxBase.'/blocklist', []],
            ['PUT', $inboxBase.'/blocklist', [
                'identity_id' => $identity->id,
                'action' => 'BLOCK',
            ]],
            ['GET', $inboxBase.'/privacy', []],
            ['PUT', $inboxBase.'/privacy', ['name' => 'profile', 'value' => 'contacts']],
            ['POST', $inboxBase.'/contacts/check', ['users' => ['+5511999997001']]],
            ['POST', $inboxBase.'/contacts/info', ['users' => ['+5511999997001']]],
            ['POST', $inboxBase.'/contacts/business-profiles', ['users' => ['+5511999997001']]],
            ['POST', $inboxBase.'/contacts/profile-picture', [
                'identity_id' => $identity->id,
                'preview' => true,
            ]],
            ['POST', $inboxBase.'/contacts/qr-link', ['revoke' => false]],
            ['POST', $inboxBase.'/contacts/qr-resolve', ['link' => 'https://wa.me/qr/example']],
            ['POST', $inboxBase.'/contacts/business-link-resolve', ['link' => 'https://wa.me/message/example']],
            ['PUT', $conversationBase.'/messages/'.$outbound->id.'/edit', ['text' => 'Corrigido']],
            ['DELETE', $conversationBase.'/messages/'.$outbound->id, []],
            ['PUT', $conversationBase.'/messages/'.$inbound->id.'/reaction', ['emoji' => '👍']],
            ['POST', $conversationBase.'/messages/'.$poll->id.'/poll-votes', ['option_names' => ['Sim']]],
            ['POST', $conversationBase.'/messages/'.$inbound->id.'/receipts', ['receipt' => 'READ']],
            ['POST', $conversationBase.'/messages/'.$inbound->id.'/history', ['count' => 10]],
            ['POST', $conversationBase.'/messages/'.$inbound->id.'/recovery', ['operation' => 'UNAVAILABLE']],
            ['POST', $conversationBase.'/presence/subscribe', []],
            ['PUT', $conversationBase.'/presence', ['presence' => 'COMPOSING']],
            ['PUT', $conversationBase.'/disappearing', ['timer_seconds' => 86400]],
            ['PUT', $conversationBase.'/state', ['action' => 'ARCHIVE', 'value' => true]],
        ];

        foreach ($requests as [$method, $uri, $payload]) {
            if ($method === 'GET') {
                $response = $this->getJson($uri.'?tenant_id='.$tenant->id);
            } else {
                $response = $this->json($method, $uri, [
                    ...$payload,
                    'tenant_id' => $tenant->id,
                ]);
            }

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors('tenant_id');
        }

        $this->assertDatabaseCount('communication_outbox_entries', 0);
        $this->assertCount(0, $this->transport->queries);
    }

    public function test_gateway_actions_reject_invalid_domain_targets_without_enqueueing(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $inbox = $this->inbox($tenant);
        [$conversation, $inbound, $outbound, $poll] = $this->conversation($tenant, $inbox);
        $otherContact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Outro contato',
            'is_active' => true,
        ]);
        $otherIdentity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $otherContact->id,
            'channel' => 'WHATSAPP',
            'address_encrypted' => '+5511999997002',
            'address_hash' => hash('sha256', '+5511999997002'),
            'address_masked' => '***7002',
            'is_active' => true,
        ]);
        $otherConversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $otherIdentity->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ]);
        $otherInbound = $this->message(
            $tenant,
            $inbox,
            $otherConversation,
            MessageDirection::Inbound,
            MessageKind::Text,
            'provider-other-inbound-0001',
        );
        $base = '/api/v1/communication/conversations/'.$conversation->id;
        $this->authenticate($admin);

        $this->putJson($base.'/messages/'.$inbound->id.'/edit', ['text' => 'Inválido'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'outbound_message_required');
        $this->deleteJson($base.'/messages/'.$inbound->id)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'outbound_message_required');
        $this->postJson($base.'/messages/'.$outbound->id.'/receipts', ['receipt' => 'READ'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'inbound_message_required');
        $this->postJson($base.'/messages/'.$outbound->id.'/recovery', ['operation' => 'UNAVAILABLE'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'inbound_message_required');
        $this->postJson($base.'/messages/'.$inbound->id.'/poll-votes', ['option_names' => ['Sim']])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'poll_message_required');
        $this->putJson($base.'/messages/'.$otherInbound->id.'/reaction', ['emoji' => '👍'])
            ->assertNotFound();
        $this->putJson('/api/v1/communication/conversations/'.$otherConversation->id.'/messages/'.$poll->id.'/reaction', [
            'emoji' => '👍',
        ])->assertNotFound();

        $this->assertDatabaseCount('communication_outbox_entries', 0);
    }

    public function test_admin_controls_use_the_current_inbox_session_and_sanitized_queries(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $inbox = $this->inbox($tenant, 'session-admin-actions-0001');
        [$conversation] = $this->conversation($tenant, $inbox);
        $identity = CommunicationIdentity::query()
            ->withoutGlobalScopes()
            ->findOrFail($conversation->identity_id);
        $this->authenticate($admin);

        $commandResponse = $this->putJson('/api/v1/communication/inboxes/'.$inbox->id.'/privacy', [
            'name' => 'profile', 'value' => 'contacts',
        ])->assertStatus(202)->assertJsonPath('data.type', GatewayCommandType::UpdatePrivacy->value);
        $this->assertSame(
            ['command_id', 'type', 'status'],
            array_keys($commandResponse->json('data')),
        );
        $this->putJson('/api/v1/communication/inboxes/'.$inbox->id.'/blocklist', [
            'identity_id' => $identity->id, 'action' => 'BLOCK',
        ])->assertStatus(202)->assertJsonPath('data.type', GatewayCommandType::UpdateBlocklist->value);
        $this->putJson('/api/v1/communication/inboxes/'.$inbox->id.'/presence', [
            'presence' => 'AVAILABLE', 'force_active_delivery_receipts' => true,
        ])->assertStatus(202)->assertJsonPath('data.type', GatewayCommandType::SetPresence->value);
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/session/connect')
            ->assertStatus(202)->assertJsonPath('data.type', GatewayCommandType::ConnectSession->value);
        $this->putJson('/api/v1/communication/inboxes/'.$inbox->id.'/session/passive', ['passive' => true])
            ->assertStatus(202)->assertJsonPath('data.type', GatewayCommandType::SetPassive->value);
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/session/pair-phone', [
            'phone' => '+5511999997001', 'show_push_notification' => false,
        ])->assertStatus(202)->assertJsonPath('data.type', GatewayCommandType::PairPhone->value);
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/session/passkey/respond', [
            'id' => 'passkey-request-0001',
            'client_data_json' => 'client-data',
            'authenticator_data' => 'authenticator-data',
            'signature' => 'signature-data',
        ])->assertStatus(202)->assertJsonPath('data.type', GatewayCommandType::RespondPasskey->value);
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/session/passkey/confirm', [
            'id' => 'passkey-request-0001', 'confirm' => true,
        ])->assertStatus(202)->assertJsonPath('data.type', GatewayCommandType::ConfirmPasskey->value);
        $this->putJson('/api/v1/communication/inboxes/'.$inbox->id.'/default-disappearing', [
            'timer_seconds' => 604800,
        ])->assertStatus(202)->assertJsonPath('data.type', GatewayCommandType::SetDefaultDisappearing->value);
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/app-state/sync')
            ->assertStatus(202)->assertJsonPath('data.type', GatewayCommandType::UpdateChatState->value);
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/app-state/mark-clean', [
            'timestamp' => 1784746800,
        ])->assertStatus(202)->assertJsonPath('data.type', GatewayCommandType::UpdateChatState->value);
        $this->getJson('/api/v1/communication/inboxes/'.$inbox->id.'/session/status')
            ->assertOk()
            ->assertJsonPath('data.session_id', 'session-admin-actions-0001')
            ->assertJsonPath('data.status', InboxStatus::Connected->value)
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.logged_in', true)
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.has_credentials', true);
        $queryResponse = $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/contacts/check', [
            'users' => ['+5511999997001'],
        ])->assertOk()->assertJsonPath('data.type', 'USER_CHECK');
        $this->assertSame(['type'], array_keys($queryResponse->json('data')));
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/contacts/info', [
            'users' => ['+5511999997001'],
        ])->assertOk()->assertJsonPath('data.type', 'USER_INFO');
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/contacts/business-profiles', [
            'users' => ['+5511999997001'],
        ])->assertOk()->assertJsonPath('data.type', 'BUSINESS_PROFILE');
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/contacts/profile-picture', [
            'identity_id' => $identity->id, 'preview' => true,
        ])->assertOk()
            ->assertJsonPath('data.type', 'PROFILE_PICTURE')
            ->assertJsonMissingPath('data.url')
            ->assertJsonMissingPath('data.user')
            ->assertJsonMissingPath('data.id');
        Queue::assertPushed(RefreshCommunicationProfilePictureJob::class);
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/contacts/qr-link', [
            'revoke' => false,
        ])->assertOk()->assertJsonPath('data.type', 'CONTACT_QR_LINK');
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/contacts/qr-resolve', [
            'link' => 'https://wa.me/qr/example',
        ])->assertOk()->assertJsonPath('data.type', 'CONTACT_QR_RESOLVE');
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/contacts/business-link-resolve', [
            'link' => 'https://wa.me/message/example',
        ])->assertOk()->assertJsonPath('data.type', 'BUSINESS_LINK_RESOLVE');
        $this->getJson('/api/v1/communication/inboxes/'.$inbox->id.'/blocklist')
            ->assertOk()->assertJsonPath('data.type', 'BLOCKLIST');
        $this->getJson('/api/v1/communication/inboxes/'.$inbox->id.'/privacy')
            ->assertOk()->assertJsonPath('data.type', 'PRIVACY_SETTINGS');
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/session/disconnect')
            ->assertStatus(202)->assertJsonPath('data.type', GatewayCommandType::DisconnectSession->value);
        $this->assertSame(InboxStatus::Disconnected, $inbox->refresh()->status);

        $this->assertCount(8, $this->transport->queries);
        foreach ($this->transport->queries as $query) {
            $this->assertSame('session-admin-actions-0001', $query->sessionId);
            $this->assertNotSame(GatewayQueryType::ProfilePicture, $query->type);
        }
        $block = CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->where('type', GatewayCommandType::UpdateBlocklist->value)->firstOrFail();
        $this->assertSame('+5511999997001', $block->payload_encrypted['to']);
        $this->assertArrayNotHasKey('tenant_id', $block->payload_encrypted);
        $this->assertArrayNotHasKey('session_id', $block->payload_encrypted);
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }

    private function inbox(Tenant $tenant, ?string $sessionId = null): CommunicationInbox
    {
        return CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Inbox '.Str::random(6),
            'session_id' => $sessionId ?? 'session-'.strtolower((string) Str::ulid()),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
        ]);
    }

    private function member(CommunicationInbox $inbox, User $user): void
    {
        $membership = TenantMembership::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)->where('user_id', $user->id)->firstOrFail();
        CommunicationInboxMember::query()->withoutGlobalScopes()->create([
            'tenant_id' => $inbox->tenant_id,
            'inbox_id' => $inbox->id,
            'tenant_membership_id' => $membership->id,
            'is_active' => true,
        ]);
    }

    /** @return array{CommunicationConversation,CommunicationMessage,CommunicationMessage,CommunicationMessage} */
    private function conversation(Tenant $tenant, CommunicationInbox $inbox): array
    {
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Contato', 'is_active' => true,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => 'WHATSAPP',
            'address_encrypted' => '+5511999997001',
            'address_hash' => hash('sha256', '+5511999997001'),
            'address_masked' => '***7001',
            'is_active' => true,
        ]);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ]);
        $inbound = $this->message($tenant, $inbox, $conversation, MessageDirection::Inbound, MessageKind::Text, 'provider-inbound-0001');
        $outbound = $this->message($tenant, $inbox, $conversation, MessageDirection::Outbound, MessageKind::Text, 'provider-outbound-0001');
        $poll = $this->message($tenant, $inbox, $conversation, MessageDirection::Inbound, MessageKind::Poll, 'provider-poll-0001');

        return [$conversation->load('identity'), $inbound, $outbound, $poll];
    }

    private function message(
        Tenant $tenant,
        CommunicationInbox $inbox,
        CommunicationConversation $conversation,
        MessageDirection $direction,
        MessageKind $kind,
        string $providerId,
    ): CommunicationMessage {
        return CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $conversation->identity_id,
            'direction' => $direction,
            'kind' => $kind,
            'source' => $direction === MessageDirection::Inbound ? MessageSource::Gateway : MessageSource::Human,
            'status' => $direction === MessageDirection::Inbound ? MessageStatus::Delivered : MessageStatus::Sent,
            'body_encrypted' => 'Mensagem '.$providerId,
            'provider_message_id' => $providerId,
            'content_digest' => hash('sha256', $providerId),
            'occurred_at' => now(),
        ]);
    }
}

final class ActionApiTransport implements CommunicationTransport
{
    /** @var list<GatewayCommandData> */
    public array $commands = [];

    /** @var list<GatewayQueryData> */
    public array $queries = [];

    public function dispatch(GatewayCommandData $command): GatewayCommandReceipt
    {
        $this->commands[] = $command;

        return new GatewayCommandReceipt($command->commandId, false);
    }

    public function query(GatewayQueryData $query): array
    {
        $this->queries[] = $query;

        return ['type' => $query->type->value];
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
        throw new CommunicationTransportException('MEDIA_NOT_CONFIGURED', false);
    }
}
