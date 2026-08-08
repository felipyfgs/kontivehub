<?php

namespace Tests\Feature\Communication;

use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayCommandReceipt;
use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\GatewayQueryType;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\OutboxStatus;
use App\Enums\Communication\SignatureVerificationResult;
use App\Enums\TenantRole;
use App\Exceptions\CommunicationTransportException;
use App\Exceptions\CommunicationUnavailableException;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxMember;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Communication\Gateway\GatewayOperationPolicy;
use App\Services\Communication\Gateway\GatewayOperations;
use App\Services\Communication\Outbox\OutboxDispatcher;
use App\Services\Communication\Outbox\OutboxService;
use App\Services\Communication\Security\HmacCanonicalizer;
use App\Services\Communication\Security\HmacVerifier;
use App\Services\Communication\Transport\HttpTransport;
use App\Support\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

final class CommunicationGatewayTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'cache.default' => 'array',
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
            'communication.gateway.base_url' => 'http://wazync.test',
            'communication.hmac.current_key_id' => 'laravel-test-v1',
            'communication.hmac.current_secret' => str_repeat('q', 32),
            'communication.hmac.previous_key_id' => '',
            'communication.hmac.previous_secret' => '',
            'communication.hmac.window_seconds' => 300,
            'communication.hmac.nonce_ttl_seconds' => 600,
        ]);
    }

    public function test_query_is_hmac_signed_with_fresh_nonce_and_replay_is_rejected(): void
    {
        Http::fake(fn (Request $request) => Http::response([
            'contract_version' => 'v1',
            'query_id' => 'query-user-check-0001',
            'result' => ['users' => [['input' => '+5511999991234', 'exists' => true]]],
        ]));
        $query = new GatewayQueryData(
            queryId: 'query-user-check-0001',
            sessionId: 'session-query-0001',
            type: GatewayQueryType::CheckUsers,
            payload: ['users' => ['+5511999991234']],
        );
        $transport = app(HttpTransport::class);

        $this->assertTrue($transport->query($query)['users'][0]['exists']);
        $this->assertTrue($transport->query($query)['users'][0]['exists']);

        $requests = Http::recorded()->map(fn (array $record): Request => $record[0])->values();
        $this->assertCount(2, $requests);
        $this->assertSame('POST', $requests[0]->method());
        $this->assertSame('http://wazync.test/internal/v1/queries', $requests[0]->url());
        $this->assertNotSame(
            $this->header($requests[0], 'X-Communication-Nonce'),
            $this->header($requests[1], 'X-Communication-Nonce'),
        );

        $headers = $requests[0]->headers();
        $timestamp = (int) $this->header($requests[0], 'X-Communication-Timestamp');
        $verifier = new HmacVerifier(
            app(HmacCanonicalizer::class),
            app(CacheRepository::class),
        );
        $this->assertSame(SignatureVerificationResult::Valid, $verifier->verify(
            'POST',
            '/internal/v1/queries',
            $requests[0]->body(),
            $headers,
            $timestamp,
        ));
        $this->assertSame(SignatureVerificationResult::Replay, $verifier->verify(
            'POST',
            '/internal/v1/queries',
            $requests[0]->body(),
            $headers,
            $timestamp + 1,
        ));
    }

    public function test_query_rejects_sensitive_or_mismatched_gateway_response(): void
    {
        Http::fakeSequence()
            ->push([
                'contract_version' => 'v1',
                'query_id' => 'query-user-info-0001',
                'result' => ['users' => [['user' => '+5511999991234', 'direct_path' => '/secret']]],
            ])
            ->push([
                'contract_version' => 'v1',
                'query_id' => 'query-user-info-0001',
                'result' => ['user_info' => [[
                    'user' => '+5511999991234',
                    'status' => 'Disponível',
                    'device_count' => 2,
                ]]],
            ])
            ->push([
                'contract_version' => 'v1',
                'query_id' => 'query-another-0001',
                'result' => ['users' => []],
            ]);
        $query = new GatewayQueryData(
            queryId: 'query-user-info-0001',
            sessionId: 'session-query-0001',
            type: GatewayQueryType::UserInfo,
            payload: ['users' => ['+5511999991234']],
        );
        $transport = app(HttpTransport::class);

        try {
            $transport->query($query);
            $this->fail('Resposta sensível deveria falhar fechada.');
        } catch (CommunicationTransportException $error) {
            $this->assertSame('GATEWAY_UNSAFE_QUERY_RESULT', $error->errorCode);
            $this->assertFalse($error->retryable);
        }

        try {
            $transport->query($query);
            $this->fail('Campo extra fora do schema deveria falhar fechado.');
        } catch (CommunicationTransportException $error) {
            $this->assertSame('GATEWAY_UNSAFE_QUERY_RESULT', $error->errorCode);
            $this->assertFalse($error->retryable);
        }

        $this->expectException(CommunicationTransportException::class);
        $this->expectExceptionMessage('GATEWAY_INVALID_QUERY_RESULT');
        $transport->query($query);
    }

    public function test_gateway_errors_are_reduced_to_allowlisted_codes_before_rethrowing(): void
    {
        $unsafe = "https://cdn.example.test/private/avatar\n5511999991234@s.whatsapp.net";
        Http::fakeSequence()
            ->push(['error' => 'PROFILE_PICTURE_PRIVACY'], 403)
            ->push(['error' => 'PROFILE_PICTURE_NOT_FOUND'], 404)
            ->push(['error' => $unsafe], 502);
        $transport = app(HttpTransport::class);
        $query = new GatewayQueryData(
            queryId: 'query-profile-picture-errors',
            sessionId: 'session-query-0001',
            type: GatewayQueryType::ProfilePicture,
            payload: ['user' => '+5511999991234', 'preview' => true],
        );

        foreach ([
            ['PROFILE_PICTURE_PRIVACY', false, 403],
            ['PROFILE_PICTURE_NOT_FOUND', false, 404],
            ['GATEWAY_HTTP_502', true, 502],
        ] as [$expectedCode, $retryable, $status]) {
            try {
                $transport->query($query);
                self::fail('Resposta de erro do gateway deveria lançar exceção segura.');
            } catch (CommunicationTransportException $error) {
                self::assertSame($expectedCode, $error->errorCode);
                self::assertSame($retryable, $error->retryable);
                self::assertSame($status, $error->httpStatus);
                self::assertSame($expectedCode, $error->getMessage());
                self::assertStringNotContainsString($unsafe, $error->getMessage());
                self::assertStringNotContainsString('cdn.example.test', $error->getMessage());
                self::assertStringNotContainsString('@s.whatsapp.net', $error->getMessage());
            }
        }
    }

    public function test_session_status_requires_the_enriched_three_state_contract(): void
    {
        Http::fakeSequence()
            ->push([
                'session_id' => 'session-status-0001',
                'status' => 'DISCONNECTED',
                'desired_connected' => false,
                'reconnect_count' => 0,
                'connected' => false,
                'logged_in' => false,
                'ready' => false,
                'has_credentials' => true,
            ])
            ->push([
                'session_id' => 'session-status-0001',
                'status' => 'PAIRING',
                'desired_connected' => true,
                'reconnect_count' => 0,
                'connected' => false,
                'logged_in' => false,
                'ready' => false,
                'has_credentials' => false,
            ]);
        $transport = app(HttpTransport::class);

        $status = $transport->sessionStatus('session-status-0001');
        $this->assertSame('DISCONNECTED', $status['status']);
        $this->assertTrue($status['has_credentials']);

        try {
            $transport->sessionStatus('session-status-0001');
            $this->fail('O transporte não pode expor status fora do contrato.');
        } catch (CommunicationTransportException $error) {
            $this->assertSame('GATEWAY_INVALID_SESSION_STATUS', $error->errorCode);
            $this->assertTrue($error->retryable);
        }
    }

    public function test_media_spool_ack_is_signed_and_idempotent_at_the_http_boundary(): void
    {
        Http::fake(fn () => Http::response('', 204));

        app(HttpTransport::class)->acknowledgeMedia('media-ack-0001');

        Http::assertSent(function (Request $request): bool {
            $timestamp = (int) $this->header($request, 'X-Communication-Timestamp');
            $verification = app(HmacVerifier::class)->verify(
                'DELETE',
                '/internal/v1/media/media-ack-0001',
                '',
                $request->headers(),
                $timestamp,
            );

            return $request->method() === 'DELETE'
                && $request->url() === 'http://wazync.test/internal/v1/media/media-ack-0001'
                && $verification === SignatureVerificationResult::Valid;
        });
    }

    public function test_operations_apply_reply_manage_and_tenant_inbox_boundaries(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $foreignAdmin = User::factory()->forTenant($foreignTenant, TenantRole::TenantAdmin)->create();
        $inbox = $this->inbox($tenant, 'session-own-0001');
        $this->member($inbox, $operator);
        $foreignInbox = $this->inbox($foreignTenant, 'session-foreign-0001');
        $transport = new GatewayTransportProbe;
        $this->app->instance(CommunicationTransport::class, $transport);
        $operations = app(GatewayOperations::class);

        $this->bindActor($operator);
        $entry = $operations->enqueue($operator, $inbox, GatewayCommandType::MarkMessage, [
            'to' => '+5511999991234',
            'message_ids' => ['message-target-0001'],
            'receipt' => 'READ',
        ]);
        $this->assertSame((int) $tenant->id, (int) $entry->tenant_id);
        $this->assertSame((int) $inbox->id, (int) $entry->inbox_id);
        $this->assertSame($inbox->session_id, $entry->session_id);

        try {
            $operations->enqueue($operator, $inbox, GatewayCommandType::UpdatePrivacy, [
                'name' => 'last',
                'value' => 'contacts',
            ]);
            $this->fail('Operador não deveria alterar privacidade.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('communication_outbox_entries', 1);
        }

        $this->bindActor($admin);
        $result = $operations->query(
            $admin,
            $inbox,
            GatewayQueryType::CheckUsers,
            ['users' => ['+5511999991234']],
            'query-tenant-own-0001',
        );
        $this->assertSame('session-own-0001', $transport->queries[0]->sessionId);
        $this->assertSame('USER_CHECK', $result['type']);

        $this->bindActor($foreignAdmin);
        try {
            $operations->query(
                $foreignAdmin,
                $inbox,
                GatewayQueryType::PrivacySettings,
                [],
                'query-tenant-foreign-0001',
            );
            $this->fail('Tenant estrangeiro não deveria consultar esta inbox.');
        } catch (AuthorizationException) {
            $this->assertCount(1, $transport->queries);
        }

        $this->bindActor($admin);
        try {
            $operations->query(
                $admin,
                $foreignInbox,
                GatewayQueryType::Blocklist,
                [],
                'query-foreign-inbox-0001',
            );
            $this->fail('Inbox estrangeira não deveria ser acessível pelo Tenant ativo.');
        } catch (AuthorizationException) {
            $this->assertCount(1, $transport->queries);
        }
    }

    public function test_worker_rechecks_kill_switch_and_never_calls_transport_after_enqueue(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant, 'session-kill-switch-0001');
        $entry = app(OutboxService::class)->enqueue(
            $inbox,
            GatewayCommandType::MarkMessage,
            [
                'to' => '+5511999991234',
                'message_ids' => ['message-target-0001'],
                'receipt' => 'READ',
            ],
            commandId: 'command-kill-switch-0001',
        );
        $transport = new GatewayTransportProbe;
        $this->app->instance(CommunicationTransport::class, $transport);

        $tenant->forceFill(['communication_enabled' => false])->save();
        app(OutboxDispatcher::class)->dispatch((int) $entry->id);

        $this->assertSame(OutboxStatus::Dead, $entry->refresh()->status);
        $this->assertSame('TENANT_COMMUNICATION_DISABLED', $entry->last_error_code);
        $this->assertCount(0, $transport->commands);
    }

    public function test_worker_dispatches_disconnect_and_logout_after_administrative_switches_close(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant, 'session-admin-off-0001');
        $outbox = app(OutboxService::class);
        $disconnect = $outbox->enqueue(
            $inbox,
            GatewayCommandType::DisconnectSession,
            [],
            commandId: 'command-disconnect-admin-off-0001',
        );
        $logout = $outbox->enqueue(
            $inbox,
            GatewayCommandType::LogoutSession,
            [],
            commandId: 'command-logout-admin-off-0001',
        );
        $tenant->forceFill(['communication_enabled' => false])->save();
        $inbox->forceFill(['is_enabled' => false, 'status' => InboxStatus::Disconnected])->save();
        $transport = new GatewayTransportProbe;
        $this->app->instance(CommunicationTransport::class, $transport);

        app(OutboxDispatcher::class)->dispatch((int) $disconnect->id);
        app(OutboxDispatcher::class)->dispatch((int) $logout->id);

        $this->assertSame(OutboxStatus::Accepted, $disconnect->refresh()->status);
        $this->assertSame(OutboxStatus::Accepted, $logout->refresh()->status);
        $this->assertSame(
            [GatewayCommandType::DisconnectSession, GatewayCommandType::LogoutSession],
            array_map(static fn (GatewayCommandData $command) => $command->type, $transport->commands),
        );
    }

    public function test_outbox_rejects_disabled_enqueue_and_worker_rejects_tenant_tampering(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant, 'session-tenant-worker-0001');
        $outbox = app(OutboxService::class);
        config(['communication.gateway.enabled' => false]);

        try {
            $outbox->enqueue($inbox, GatewayCommandType::MarkMessage, [
                'to' => '+5511999991234',
                'message_ids' => ['message-target-0001'],
                'receipt' => 'READ',
            ]);
            $this->fail('Kill switch global deveria impedir persistência do comando.');
        } catch (CommunicationUnavailableException $error) {
            $this->assertSame('COMMUNICATION_DISABLED', $error->getMessage());
            $this->assertDatabaseCount('communication_outbox_entries', 0);
        }

        config(['communication.gateway.enabled' => true]);
        $entry = $outbox->enqueue($inbox, GatewayCommandType::MarkMessage, [
            'to' => '+5511999991234',
            'message_ids' => ['message-target-0001'],
            'receipt' => 'READ',
        ], commandId: 'command-tenant-worker-0001');
        $entry->forceFill(['tenant_id' => $foreignTenant->id])->save();
        $transport = new GatewayTransportProbe;
        $this->app->instance(CommunicationTransport::class, $transport);

        app(OutboxDispatcher::class)->dispatch((int) $entry->id);

        $this->assertSame(OutboxStatus::Dead, $entry->refresh()->status);
        $this->assertSame('OUTBOX_TENANT_SCOPE_INVALID', $entry->last_error_code);
        $this->assertCount(0, $transport->commands);
    }

    public function test_every_mutable_command_has_explicit_permission_and_connection_policy(): void
    {
        $policy = app(GatewayOperationPolicy::class);

        foreach (GatewayCommandType::cases() as $type) {
            $this->assertNotNull($policy->permissionFor($type), $type->value);
            $this->assertIsBool($policy->requiresConnectedInbox($type), $type->value);
        }
    }

    private function inbox(Tenant $tenant, string $sessionId): CommunicationInbox
    {
        return CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Inbox '.Str::random(6),
            'session_id' => $sessionId,
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

    private function bindActor(User $actor): void
    {
        app(CurrentTenant::class)->clear();
        app(CurrentTenant::class)->resolve($actor);
    }

    private function header(Request $request, string $name): string
    {
        return (string) ($request->header($name)[0] ?? '');
    }
}

final class GatewayTransportProbe implements CommunicationTransport
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
