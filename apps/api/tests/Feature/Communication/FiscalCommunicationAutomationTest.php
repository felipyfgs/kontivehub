<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\GatewayEventType;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\Communication\RecipientMode;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDispatchStatus;
use App\Enums\DctfwebArtifactKind;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Enums\PgdasdDocumentKind;
use App\Enums\TaxGuideEmissionStatus;
use App\Enums\TaxObligationApplicability;
use App\Enums\TenantRole;
use App\Events\CommunicationEventCommitted;
use App\Models\Client;
use App\Models\ClientCommunicationDispatch;
use App\Models\ClientCommunicationPreference;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationAutomationPolicy;
use App\Models\CommunicationContact;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationIdentityLink;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxMember;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Models\DctfwebDarfDocument;
use App\Models\DctfwebDeclaration;
use App\Models\FiscalMonitoringRun;
use App\Models\PgdasdArtifact;
use App\Models\TaxGuide;
use App\Models\TaxGuideVersion;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Communication\Automation\FiscalCommunicationArtifactResolver;
use App\Services\Communication\Automation\FiscalCommunicationAutomationService;
use App\Services\Communication\Media\CommunicationMediaStore;
use App\Services\Communication\Security\CommunicationHmacSigner;
use App\Services\Fiscal\Guides\GuideStorageService;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Services\Integra\Dctfweb\DctfwebEvidenceVersioningService;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class FiscalCommunicationAutomationTest extends TestCase
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
            'communication.hmac.current_key_id' => 'test-key',
            'communication.hmac.current_secret' => str_repeat('s', 32),
            'communication.media.disk_root' => sys_get_temp_dir().'/fiscal-hub-communication-tests-'.Str::ulid(),
        ]);
    }

    public function test_automatic_fanout_is_idempotent_and_queues_exact_document_per_identity(): void
    {
        [$tenant, $client, $inbox, $preference] = $this->context(RecipientMode::AllEligible);
        $first = $this->identity($tenant, $client, true);
        $second = $this->identity($tenant, $client, false);
        $this->pgdasdDocument($tenant, $client, '2026-06', CarbonImmutable::now()->subDay(), '%PDF-exact-period');

        $service = app(FiscalCommunicationAutomationService::class);
        $created = $service->scheduleAutomatic($tenant, $client, 'simples_mei', 'pgdasd', '2026-06');
        $this->assertCount(2, $created);
        $this->assertSame(0, $service->scheduleAutomatic($tenant, $client, 'simples_mei', 'pgdasd', '2026-06')->count());
        $this->assertSame([$first->id, $second->id], ClientCommunicationDispatch::query()->withoutGlobalScopes()
            ->orderBy('identity_id')->pluck('identity_id')->all());

        foreach (ClientCommunicationDispatch::query()->withoutGlobalScopes()->get() as $dispatch) {
            $dispatch->forceFill(['scheduled_at' => now()])->save();
            $processed = $service->process((int) $dispatch->id);
            $this->assertSame(CommunicationDispatchStatus::Queued, $processed?->status);
            $this->assertSame('2026-06', $processed?->period_key);
            $this->assertNotNull($processed?->artifact_id);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $processed?->artifact_digest);
            $this->assertNotNull($processed?->message_id);
        }

        $this->assertSame(2, CommunicationOutboxEntry::query()->withoutGlobalScopes()->count());
        $this->assertDatabaseCount('communication_messages', 2);
        $this->assertDatabaseCount('communication_attachments', 2);
        $this->assertDatabaseCount('communication_conversations', 2);
        $this->assertDatabaseHas('client_communication_dispatches', [
            'preference_id' => $preference->id,
            'inbox_id' => $inbox->id,
            'status' => CommunicationDispatchStatus::Queued->value,
        ]);
    }

    public function test_fake_end_to_end_runs_inbound_reply_receipt_and_automation_without_live_egress(): void
    {
        Http::preventStrayRequests();
        [$tenant, $client, $inbox] = $this->context();
        $identity = $this->identity($tenant, $client, true);
        $this->pgdasdDocument($tenant, $client, '2026-06', CarbonImmutable::now()->subDay(), '%PDF-e2e-exact');

        $this->postGatewayEvent($inbox, GatewayEventType::MessageReceived, 'gateway-e2e-inbound-0001', [
            'provider_message_id' => 'provider-e2e-inbound-0001',
            'from' => '+5511999990001',
            'kind' => 'TEXT',
            'text' => 'Olá, preciso da guia.',
        ])->assertNoContent();

        $conversation = $identity->conversations()->withoutGlobalScopes()->firstOrFail();
        $this->assertDatabaseHas('communication_messages', [
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound->value,
            'source' => MessageSource::Gateway->value,
        ]);

        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $membership = TenantMembership::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $operator->id)
            ->firstOrFail();
        CommunicationInboxMember::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'tenant_membership_id' => $membership->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($operator);
        app(CurrentTenant::class)->clear();

        $reply = $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => 'Claro, vou encaminhar a guia.',
            'idempotency_key' => 'fake-e2e-human-reply-0001',
        ])->assertStatus(202);
        $humanMessage = CommunicationMessage::query()->withoutGlobalScopes()
            ->findOrFail((int) $reply->json('data.id'));
        $this->assertSame(MessageSource::Human, $humanMessage->source);

        $this->postGatewayEvent($inbox, GatewayEventType::MessageStatusChanged, 'gateway-e2e-receipt-0001', [
            'provider_message_id' => $humanMessage->provider_message_id,
            'status' => 'READ',
        ])->assertNoContent();
        $this->assertSame(MessageStatus::Read, $humanMessage->refresh()->status);

        $service = app(FiscalCommunicationAutomationService::class);
        $dispatch = $service->scheduleAutomatic(
            $tenant,
            $client,
            'simples_mei',
            'pgdasd',
            '2026-06',
        )->firstOrFail();
        $dispatch->forceFill(['scheduled_at' => now()])->save();
        $processed = $service->process((int) $dispatch->id);
        $automationMessage = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('source', MessageSource::FiscalAutomation)
            ->firstOrFail();

        $this->assertSame(CommunicationDispatchStatus::Queued, $processed?->status);
        $this->assertSame($conversation->id, $automationMessage->conversation_id);
        $this->assertDatabaseCount('communication_messages', 3);
        $this->assertDatabaseCount('communication_outbox_entries', 2);
        $this->assertDatabaseCount('communication_attachments', 1);
    }

    public function test_wrong_or_late_document_is_terminally_skipped_and_never_reopened(): void
    {
        [$tenant, $client] = $this->context();
        $this->identity($tenant, $client, true);
        $this->pgdasdDocument($tenant, $client, '2026-05', CarbonImmutable::now()->subMonth(), '%PDF-wrong-period');
        $service = app(FiscalCommunicationAutomationService::class);
        $dispatch = $service->scheduleAutomatic($tenant, $client, 'simples_mei', 'pgdasd', '2026-06')->first();
        $dispatch->forceFill(['scheduled_at' => now()->subMinute()])->save();

        $processed = $service->process((int) $dispatch->id);
        $this->assertSame(CommunicationDispatchStatus::SkippedNoDocument, $processed?->status);
        $this->pgdasdDocument($tenant, $client, '2026-06', CarbonImmutable::now(), '%PDF-arrived-late');
        $service->process((int) $dispatch->id);

        $this->assertSame(CommunicationDispatchStatus::SkippedNoDocument, $dispatch->refresh()->status);
        $this->assertDatabaseCount('communication_outbox_entries', 0);
        $this->assertDatabaseCount('communication_messages', 0);
    }

    public function test_fgts_without_supported_guide_skips_without_text_or_attachment(): void
    {
        [$tenant, $client, $inbox, $preference] = $this->context(
            RecipientMode::Primary,
            'fgts',
            'fgts',
        );
        $this->identity($tenant, $client, true);
        $dispatch = app(FiscalCommunicationAutomationService::class)
            ->scheduleAutomatic($tenant, $client, 'fgts', 'fgts', '2026-06')->first();
        $dispatch->forceFill(['scheduled_at' => now()])->save();

        $processed = app(FiscalCommunicationAutomationService::class)->process((int) $dispatch->id);
        $this->assertSame(CommunicationDispatchStatus::SkippedNoDocument, $processed?->status);
        $this->assertSame('FGTS_GUIDE_UNSUPPORTED', $processed?->error_code);
        $this->assertDatabaseCount('communication_messages', 0);
        $this->assertDatabaseCount('communication_outbox_entries', 0);
        $this->assertSame($preference->id, $processed?->preference_id);
        $this->assertSame($inbox->id, $processed?->inbox_id);
    }

    public function test_gateway_downloads_outbound_media_only_with_valid_non_replayed_hmac(): void
    {
        [$tenant, $client] = $this->context();
        $this->identity($tenant, $client, true);
        $this->pgdasdDocument($tenant, $client, '2026-06', CarbonImmutable::now()->subDay(), '%PDF-private-exact');
        $service = app(FiscalCommunicationAutomationService::class);
        $dispatch = $service->scheduleAutomatic($tenant, $client, 'simples_mei', 'pgdasd', '2026-06')->first();
        $dispatch->forceFill(['scheduled_at' => now()])->save();
        $service->process((int) $dispatch->id);
        $command = CommunicationOutboxEntry::query()->withoutGlobalScopes()->firstOrFail()->command_id;
        $path = '/api/internal/v1/communication/gateway/media/'.$command;

        $this->get($path)->assertUnauthorized();
        $headers = app(CommunicationHmacSigner::class)->headers('GET', $path);
        $response = $this->get($path, $headers)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Length', (string) strlen('%PDF-private-exact'))
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Content-SHA256', hash('sha256', '%PDF-private-exact'));
        $this->assertSame('%PDF-private-exact', $response->streamedContent());
        $this->get($path, $headers)->assertUnauthorized();

        $attachment = CommunicationAttachment::query()->withoutGlobalScopes()->firstOrFail();
        $message = $attachment->message()->withoutGlobalScopes()->firstOrFail();
        $attachment->forceFill(['purged_at' => now()])->save();
        $this->get($path, app(CommunicationHmacSigner::class)->headers('GET', $path))
            ->assertNotFound()
            ->assertJson(['error' => 'MEDIA_NOT_FOUND']);

        $attachment->forceFill(['purged_at' => null])->save();
        $message->forceFill(['revoked_at' => now()])->save();
        $this->get($path, app(CommunicationHmacSigner::class)->headers('GET', $path))
            ->assertNotFound()
            ->assertJson(['error' => 'MEDIA_NOT_FOUND']);

        $message->forceFill(['revoked_at' => null])->save();
        app(CommunicationMediaStore::class)->delete($attachment->object_id);
        $this->get($path, app(CommunicationHmacSigner::class)->headers('GET', $path))
            ->assertNotFound()
            ->assertJson(['error' => 'MEDIA_NOT_FOUND']);
    }

    public function test_pgmei_and_dctfweb_resolvers_choose_only_confirmed_exact_period_versions(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $this->pgmeiGuide($tenant, $client, '2026-05', '%PDF-pgmei-old');
        $pgmei = $this->pgmeiGuide($tenant, $client, '2026-06', '%PDF-pgmei-exact');

        $resolver = app(FiscalCommunicationArtifactResolver::class);
        $resolvedPgmei = $resolver->resolve($tenant, $client, 'simples_mei', 'pgmei', '2026-06');
        $this->assertSame($pgmei->id, $resolvedPgmei->artifact?->id);
        $this->assertSame('%PDF-pgmei-exact', $resolver->read($resolvedPgmei->artifact, (int) $tenant->id));

        $this->dctfwebDarf($tenant, $client, '2026-05', '%PDF-darf-old');
        $darf = $this->dctfwebDarf($tenant, $client, '2026-06', '%PDF-darf-exact');
        $resolvedDctfweb = $resolver->resolve($tenant, $client, 'dctfweb', 'dctfweb', '2026-06');
        $this->assertSame($darf->id, $resolvedDctfweb->artifact?->id);
        $this->assertSame('%PDF-darf-exact', $resolver->read($resolvedDctfweb->artifact, (int) $tenant->id));
        $this->assertNull($resolver->resolve($tenant, $client, 'dctfweb', 'dctfweb', '2026-07')->artifact);
    }

    /** @return array{Tenant,Client,CommunicationInbox,ClientCommunicationPreference} */
    private function context(
        RecipientMode $mode = RecipientMode::Primary,
        string $module = 'simples_mei',
        string $submodule = 'pgdasd',
    ): array {
        $tenant = Tenant::factory()->create([
            'communication_enabled' => true,
            'timezone' => 'America/Sao_Paulo',
        ]);
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Fiscal',
            'session_id' => 'session-'.Str::ulid(),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
            'is_default' => true,
        ]);
        $preference = ClientCommunicationPreference::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'module_key' => $module,
            'submodule_key' => $submodule,
            'automatic_requested' => true,
            'email_enabled' => false,
            'whatsapp_enabled' => true,
            'recipient_mode' => $mode,
        ]);
        CommunicationAutomationPolicy::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'module_key' => $module,
            'submodule_key' => $submodule,
            'inbox_id' => $inbox->id,
            'is_enabled' => true,
            'send_day' => 28,
            'send_time' => '09:00',
            'timezone' => 'America/Sao_Paulo',
            'recipient_mode' => $mode,
            'template_key' => $submodule.'.document',
            'template_version' => '1',
        ]);

        return [$tenant, $client, $inbox, $preference];
    }

    private function identity(Tenant $tenant, Client $client, bool $primary): CommunicationIdentity
    {
        $digits = $primary ? '5511999990001' : '5511999990002';
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $primary ? 'Contato principal' : 'Contato secundário',
            'is_active' => true,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::Whatsapp,
            'address_encrypted' => '+'.$digits,
            'address_hash' => hash('sha256', '+'.$digits),
            'address_masked' => '***'.substr($digits, -4),
            'is_active' => true,
        ]);
        CommunicationIdentityLink::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'identity_id' => $identity->id,
            'client_id' => $client->id,
            'is_primary' => $primary,
            'receives_automatic' => true,
        ]);

        return $identity;
    }

    private function pgdasdDocument(
        Tenant $tenant,
        Client $client,
        string $period,
        CarbonImmutable $observedAt,
        string $bytes,
    ): PgdasdArtifact {
        $definition = TaxObligationDefinition::query()->firstOrCreate(
            ['code' => 'PGDAS_D'],
            [
                'name' => 'PGDAS-D',
                'system_code' => 'INTEGRA_SN',
                'service_code' => 'PGDASD',
                'is_active' => true,
                'sort_order' => 10,
            ],
        );
        [$year, $month] = array_map('intval', explode('-', $period));
        $projection = TaxObligationProjection::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'obligation_definition_id' => $definition->id,
            'period_key' => $period,
            'period_year' => $year,
            'period_month' => $month,
            'is_open' => true,
            'situation' => FiscalSituation::Pending,
            'delivery_status' => FiscalSituation::Pending,
            'applicability' => TaxObligationApplicability::Applicable,
        ]);
        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'MONITOR',
            'operation_key' => 'pgdasd.monitor',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'communication-test:'.Str::uuid(),
            'status' => FiscalRunStatus::Completed,
            'situation' => FiscalSituation::Unknown,
            'coverage' => FiscalCoverage::Partial,
            'mutability' => FiscalMutability::ReadOnly,
        ]);
        $evidence = app(FiscalEvidenceStore::class)->store(
            $run,
            $bytes,
            'application/pdf',
            'TEST',
            observedAt: $observedAt,
        );

        return PgdasdArtifact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'projection_id' => $projection->id,
            'fiscal_evidence_artifact_id' => $evidence->id,
            'kind' => PgdasdDocumentKind::GuiaDasPreexistente->value,
            'filename' => 'das-'.$period.'.pdf',
            'content_type' => 'application/pdf',
            'observed_at' => $observedAt,
            'source_run_id' => $run->id,
            'metadata' => ['period_key' => $period],
        ]);
    }

    private function pgmeiGuide(Tenant $tenant, Client $client, string $period, string $bytes): TaxGuideVersion
    {
        $stored = app(GuideStorageService::class)->storeDocument((int) $tenant->id, $bytes, 'application/pdf');
        $guide = TaxGuide::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'operation_key' => 'pgmei.gerardaspdf',
            'system_code' => 'INTEGRA_MEI',
            'service_code' => 'PGMEI',
            'operation_code' => 'GERAR_DAS',
            'competence_period_key' => $period,
            'logical_key' => 'pgmei|'.$client->id.'|'.$period,
        ]);
        $version = TaxGuideVersion::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'tax_guide_id' => $guide->id,
            'version_number' => 1,
            'is_current' => true,
            'emission_status' => TaxGuideEmissionStatus::Confirmed,
            'content_sha256' => $stored['content_sha256'],
            'vault_object_id' => $stored['vault_object_id'],
            'content_type' => $stored['content_type'],
            'byte_size' => $stored['byte_size'],
            'idempotency_key' => 'pgmei-test|'.$period.'|'.Str::uuid(),
            'confirmed_at' => now(),
        ]);
        $guide->forceFill(['current_version_id' => $version->id])->save();

        return $version;
    }

    private function dctfwebDarf(Tenant $tenant, Client $client, string $period, string $bytes): DctfwebDarfDocument
    {
        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_DCTFWEB',
            'service_code' => 'DCTFWEB',
            'operation_code' => 'EMITIR_DARF',
            'operation_key' => 'dctfweb.emitir_darf',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'dctfweb-test:'.Str::uuid(),
            'status' => FiscalRunStatus::Completed,
            'situation' => FiscalSituation::Unknown,
            'coverage' => FiscalCoverage::Full,
            'mutability' => FiscalMutability::ReadOnly,
        ]);
        $declaration = DctfwebDeclaration::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'period_key' => $period,
        ]);
        $stored = app(DctfwebEvidenceVersioningService::class)->storeVersioned(
            $run,
            $declaration,
            DctfwebArtifactKind::Darf,
            $bytes,
            'application/pdf',
        );

        return DctfwebDarfDocument::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'declaration_id' => $declaration->id,
            'evidence_version_id' => $stored['version']->id,
            'evidence_artifact_id' => $stored['artifact']->id,
            'issued_at' => now(),
            'content_sha256' => hash('sha256', $bytes),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function postGatewayEvent(
        CommunicationInbox $inbox,
        GatewayEventType $type,
        string $gatewayEventId,
        array $payload,
    ) {
        if ($type === GatewayEventType::MessageReceived && ($payload['kind'] ?? null) === 'TEXT') {
            $payload['provider_type'] ??= 'conversation';
            $payload['family'] ??= 'TEXT';
        }
        $path = '/api/internal/v1/communication/gateway/events';
        $event = [
            'contract_version' => 'v1',
            'gateway_event_id' => $gatewayEventId,
            'session_id' => $inbox->session_id,
            'type' => $type->value,
            'occurred_at' => now()->toIso8601String(),
            'payload' => $payload,
        ];
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = app(CommunicationHmacSigner::class)->headers('POST', $path, $body);

        return $this->json('POST', $path, $event, $headers, JSON_UNESCAPED_SLASHES);
    }
}
