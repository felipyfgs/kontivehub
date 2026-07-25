<?php

namespace Tests\Feature;

use App\Enums\FiscalProfile;
use App\Enums\MailboxAlertSeverity;
use App\Enums\MailboxSource;
use App\Enums\MailboxTriageStatus;
use App\Enums\OfficeRole;
use App\Models\Client;
use App\Models\MailboxAlert;
use App\Models\MailboxMessage;
use App\Models\Office;
use App\Models\User;
use App\Services\Integra\Mailbox\MailboxIdempotency;
use App\Services\Integra\Mailbox\MailboxVaultStore;
use App\Support\CurrentOffice;
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
        [$office, $operator, $client] = $this->tenantContext(OfficeRole::Operator);
        $message = $this->seedMessageWithBody($office, $client, 'Corpo preview texto');

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
        [$office, $operator] = $this->tenantContext(OfficeRole::Operator);
        unset($office);

        $this->getJson('/api/v1/fiscal/mailbox/state')
            ->assertStatus(422)
            ->assertJsonPath('message', 'client_id obrigatório.');
    }

    public function test_state_returns_defaults_for_unknown_client(): void
    {
        [$office, $operator, $client] = $this->tenantContext(OfficeRole::Operator);

        $this->getJson('/api/v1/fiscal/mailbox/state?client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('data.dte.status', 'UNKNOWN')
            ->assertJsonPath('data.messages.stored_message_count', 0)
            ->assertJsonPath('data.monitoring.status', 'NEVER_SYNCED');
    }

    public function test_alerts_list_active_only(): void
    {
        [$office, $operator, $client] = $this->tenantContext(OfficeRole::Operator);
        $message = $this->seedMessageWithBody($office, $client, 'x');

        MailboxAlert::query()->create([
            'office_id' => $office->id,
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
        [$office, $operator, $client] = $this->tenantContext(OfficeRole::Operator);
        $otherOffice = Office::factory()->create(['is_active' => true]);
        $otherClient = Client::factory()->for($otherOffice)->create();
        $foreign = $this->seedMessageWithBody($otherOffice, $otherClient, 'segredo');

        $this->getJson('/api/v1/fiscal/mailbox/messages/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_viewer_can_read_but_triage_blocked_by_mutation_gate(): void
    {
        [$office, $viewer, $client] = $this->tenantContext(OfficeRole::Viewer);
        $message = $this->seedMessageWithBody($office, $client, 'v');

        $this->getJson('/api/v1/fiscal/mailbox/messages/'.$message->id)->assertOk();

        $this->patchJson('/api/v1/fiscal/mailbox/messages/'.$message->id.'/triage', [
            'triage_status' => 'RESOLVED',
        ])->assertForbidden();
    }

    /** @return array{0: Office, 1: User, 2: Client} */
    private function tenantContext(OfficeRole $role): array
    {
        $office = Office::factory()->create(['is_active' => true]);
        $actor = User::factory()->forOffice($office, $role)->create();
        $client = Client::factory()->for($office)->create();
        Sanctum::actingAs($actor);
        $currentOffice = app(CurrentOffice::class);
        $currentOffice->clear();
        $this->assertSame($office->id, $currentOffice->resolve($actor)?->id);

        return [$office, $actor, $client];
    }

    private function seedMessageWithBody(Office $office, Client $client, string $body): MailboxMessage
    {
        $externalId = 'EXT-'.substr(hash('sha256', $body.microtime(true)), 0, 12);
        $stored = app(MailboxVaultStore::class)->putBody((int) $office->id, $body);

        return MailboxMessage::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'external_id' => $externalId,
            'message_hash' => MailboxIdempotency::messageHash((int) $office->id, (int) $client->id, $externalId),
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
