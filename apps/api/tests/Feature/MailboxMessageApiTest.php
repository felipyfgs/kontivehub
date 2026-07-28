<?php

namespace Tests\Feature;

use App\Enums\FiscalProfile;
use App\Enums\MailboxAlertSeverity;
use App\Enums\MailboxSource;
use App\Enums\MailboxTriageStatus;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\MailboxAlert;
use App\Models\MailboxMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Integra\Mailbox\MailboxIdempotency;
use App\Services\Integra\Mailbox\MailboxVaultStore;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MailboxMessageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fiscal.profile' => FiscalProfile::Dev->value,
            'fiscal.kill_switch' => false,
        ]);
    }

    public function test_list_show_and_body_round_trip(): void
    {
        [$tenant, $operator, $client] = $this->tenantContext(TenantRole::TenantUser);
        $message = $this->seedMessageWithBody($tenant, $client, 'Corpo preview texto');

        $this->getJson('/api/v1/fiscal/mailbox/messages')
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->id)
            ->assertJsonMissingPath('data.0.body')
            ->assertJsonPath('data.0.has_body', true);

        $this->getJson('/api/v1/fiscal/mailbox/messages/'.$message->id)
            ->assertOk()
            ->assertJsonPath('data.id', $message->id)
            ->assertJsonPath('meta.official_read_unchanged', true);

        $body = $this->get('/api/v1/fiscal/mailbox/messages/'.$message->id.'/body');
        $body->assertOk();
        $this->assertStringContainsString('text/plain', (string) $body->headers->get('Content-Type'));
        $this->assertSame('Corpo preview texto', $body->streamedContent());
    }

    public function test_state_requires_client_id(): void
    {
        [$tenant, $operator] = $this->tenantContext(TenantRole::TenantUser);
        unset($tenant);

        $this->getJson('/api/v1/fiscal/mailbox/state')
            ->assertStatus(422)
            ->assertJsonPath('message', 'client_id obrigatório.');
    }

    public function test_state_returns_defaults_for_unknown_client(): void
    {
        [$tenant, $operator, $client] = $this->tenantContext(TenantRole::TenantUser);

        $this->getJson('/api/v1/fiscal/mailbox/state?client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('data.dte.status', 'UNKNOWN')
            ->assertJsonPath('data.messages.stored_message_count', 0)
            ->assertJsonPath('data.monitoring.status', 'NEVER_SYNCED');
    }

    public function test_alerts_list_active_only(): void
    {
        [$tenant, $operator, $client] = $this->tenantContext(TenantRole::TenantUser);
        $message = $this->seedMessageWithBody($tenant, $client, 'x');

        MailboxAlert::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'mailbox_message_id' => $message->id,
            'severity' => MailboxAlertSeverity::High,
            'title' => 'Prazo próximo',
            'body' => 'Alerta sanitizado',
            'deep_link' => '/monitoring/mailbox/'.$message->id,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/fiscal/mailbox/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Prazo próximo')
            ->assertJsonPath('data.0.mailbox_message_id', $message->id);
    }

    public function test_show_404_for_foreign_message(): void
    {
        [$tenant, $operator, $client] = $this->tenantContext(TenantRole::TenantUser);
        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        $otherClient = Client::factory()->for($otherTenant)->create();
        $foreign = $this->seedMessageWithBody($otherTenant, $otherClient, 'segredo');

        $this->getJson('/api/v1/fiscal/mailbox/messages/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_viewer_can_read_but_triage_blocked_by_mutation_gate(): void
    {
        [$tenant, $viewer, $client] = $this->tenantContext(TenantRole::TenantUser);
        $message = $this->seedMessageWithBody($tenant, $client, 'v');

        $this->getJson('/api/v1/fiscal/mailbox/messages/'.$message->id)->assertOk();

        $this->patchJson('/api/v1/fiscal/mailbox/messages/'.$message->id.'/triage', [
            'triage_status' => 'RESOLVED',
        ])->assertForbidden();
    }

    public function test_read_filters_are_validated_and_tenant_scope_is_rejected(): void
    {
        [$tenant] = $this->tenantContext(TenantRole::TenantUser);

        $this->getJson('/api/v1/fiscal/mailbox/messages?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
        $this->getJson(
            '/api/v1/fiscal/mailbox/messages?triage_status=INVALID',
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['triage_status']);
        $this->getJson('/api/v1/fiscal/mailbox/state?client_id=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id']);
        $this->getJson('/api/v1/fiscal/mailbox/alerts?active_only=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['active_only']);
        $this->getJson(
            '/api/v1/fiscal/mailbox/messages?tenant_id='.$tenant->id,
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id']);
    }

    /** @return array{0: Tenant, 1: User, 2: Client} */
    private function tenantContext(TenantRole $role): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->forTenant($tenant, $role)->create();
        $client = Client::factory()->for($tenant)->create();
        Sanctum::actingAs($actor);
        $currentTenant = app(CurrentTenant::class);
        $currentTenant->clear();
        $this->assertSame($tenant->id, $currentTenant->resolve($actor)?->id);

        return [$tenant, $actor, $client];
    }

    private function seedMessageWithBody(Tenant $tenant, Client $client, string $body): MailboxMessage
    {
        $externalId = 'EXT-'.substr(hash('sha256', $body.microtime(true)), 0, 12);
        $stored = app(MailboxVaultStore::class)->putBody((int) $tenant->id, $body);

        return MailboxMessage::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'external_id' => $externalId,
            'message_hash' => MailboxIdempotency::messageHash((int) $tenant->id, (int) $client->id, $externalId),
            'source' => MailboxSource::CaixaPostal,
            'sensitivity_class' => 'FISCAL_RESTRICTED',
            'subject_preview' => 'Assunto teste',
            'sender_label' => 'RFB',
            'official_read_indicator' => false,
            'triage_status' => MailboxTriageStatus::New,
            'body_vault_object_id' => $stored['vault_object_id'],
            'body_sha256' => $stored['sha256'],
            'body_content_type' => 'text/plain',
            'body_byte_size' => $stored['byte_size'],
            'has_body' => true,
            'attachment_count' => 0,
        ]);
    }
}
