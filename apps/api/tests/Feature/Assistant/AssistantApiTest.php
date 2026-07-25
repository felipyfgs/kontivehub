<?php

namespace Tests\Feature\Assistant;

use App\Contracts\AssistantLlmGateway;
use App\Enums\OfficeRole;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\AssistantConversation;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\ProcessTemplate;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Services\Assistant\FakeAssistantLlmGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistantApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeAssistantLlmGateway $llm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llm = new FakeAssistantLlmGateway;
        $this->app->instance(AssistantLlmGateway::class, $this->llm);
    }

    public function test_assistant_routes_return_503_when_fail_closed(): void
    {
        config([
            'assistant.enabled' => false,
            'assistant.openai.api_key' => '',
        ]);
        [$admin] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/assistant/conversations')
            ->assertStatus(503)
            ->assertJsonPath('code', 'ASSISTANT_DISABLED');

        $this->postJson('/api/v1/assistant/conversations', [])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ASSISTANT_DISABLED');

        $this->assertSame(0, $this->llm->callCount());
    }

    public function test_me_exposes_assistant_enabled_meta(): void
    {
        [$admin] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($admin);

        config([
            'assistant.enabled' => false,
            'assistant.openai.api_key' => '',
        ]);
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.assistant.enabled', false);

        config([
            'assistant.enabled' => true,
            'assistant.openai.api_key' => 'sk-test',
        ]);
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.assistant.enabled', true);
    }

    public function test_chat_persists_messages_with_fake_llm_and_isolates_tenants(): void
    {
        $this->enableAssistant();
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $otherOffice = Office::factory()->create();
        $otherUser = User::factory()->forOffice($otherOffice, OfficeRole::Admin)->create();
        $otherUser->forceFill(['selected_office_id' => $otherOffice->id])->saveQuietly();

        Sanctum::actingAs($admin);
        $conversationId = $this->postJson('/api/v1/assistant/conversations', [
            'title' => 'Ajuda Work',
            'office_id' => $otherOffice->id,
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Ajuda Work')
            ->json('data.id');

        $this->assertDatabaseHas('assistant_conversations', [
            'id' => $conversationId,
            'office_id' => $office->id,
            'user_id' => $admin->id,
        ]);

        $this->llm->enqueue([
            'content' => 'Posso listar os modelos do escritório.',
        ]);

        $this->postJson("/api/v1/assistant/conversations/{$conversationId}/chat", [
            'message' => 'Olá',
            'format' => 'json',
        ])->assertOk()
            ->assertJsonPath('data.assistant_text', 'Posso listar os modelos do escritório.');

        $this->getJson("/api/v1/assistant/conversations/{$conversationId}/messages")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        Sanctum::actingAs($otherUser);
        $this->getJson("/api/v1/assistant/conversations/{$conversationId}/messages")
            ->assertNotFound();
        $this->postJson("/api/v1/assistant/conversations/{$conversationId}/chat", [
            'message' => 'Intruso',
            'format' => 'json',
        ])->assertNotFound();

        $this->assertSame(1, $this->llm->callCount());
    }

    public function test_create_tool_without_approval_does_not_persist_template(): void
    {
        $this->enableAssistant();
        [$admin] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($admin);

        $conversationId = $this->postJson('/api/v1/assistant/conversations')
            ->assertCreated()
            ->json('data.id');

        $this->llm->enqueue([
            'content' => 'Vou propor a criação.',
            'tool_calls' => [[
                'id' => 'call_create_1',
                'name' => 'create_process_template',
                'arguments' => ['name' => 'Modelo Pendente'],
            ]],
        ]);

        $response = $this->postJson("/api/v1/assistant/conversations/{$conversationId}/chat", [
            'message' => 'Crie um modelo chamado Modelo Pendente',
            'format' => 'json',
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.pending_approvals'));
        $this->assertDatabaseMissing('process_templates', ['name' => 'Modelo Pendente']);
        $this->assertSame(0, ProcessTemplate::query()->where('name', 'Modelo Pendente')->count());
    }

    public function test_create_with_approval_and_permission_persists(): void
    {
        $this->enableAssistant();
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($admin);

        $conversationId = $this->postJson('/api/v1/assistant/conversations')
            ->assertCreated()
            ->json('data.id');

        $this->llm->enqueue([
            'content' => 'Confirme a criação.',
            'tool_calls' => [[
                'id' => 'call_create_2',
                'name' => 'create_process_template',
                'arguments' => [
                    'name' => 'Modelo Aprovado',
                    'description' => 'Via assistente',
                    'is_active' => true,
                ],
            ]],
        ]);

        $token = $this->postJson("/api/v1/assistant/conversations/{$conversationId}/chat", [
            'message' => 'Crie Modelo Aprovado',
            'format' => 'json',
        ])->assertOk()
            ->json('data.pending_approvals.0.approval_token');

        $this->assertNotEmpty($token);
        $this->assertDatabaseMissing('process_templates', ['name' => 'Modelo Aprovado']);

        $this->postJson("/api/v1/assistant/conversations/{$conversationId}/approve-tool", [
            'approval_token' => $token,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.result.name', 'Modelo Aprovado');

        $this->assertDatabaseHas('process_templates', [
            'office_id' => $office->id,
            'name' => 'Modelo Aprovado',
        ]);
    }

    public function test_create_approval_without_catalog_manage_returns_403(): void
    {
        $this->enableAssistant();
        [$admin] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($admin);

        $conversationId = $this->postJson('/api/v1/assistant/conversations')
            ->assertCreated()
            ->json('data.id');

        $this->llm->enqueue([
            'tool_calls' => [[
                'id' => 'call_create_3',
                'name' => 'create_process_template',
                'arguments' => ['name' => 'Negado Viewer'],
            ]],
        ]);

        $token = $this->postJson("/api/v1/assistant/conversations/{$conversationId}/chat", [
            'message' => 'Crie',
            'format' => 'json',
        ])->assertOk()
            ->json('data.pending_approvals.0.approval_token');

        $officeId = AssistantConversation::query()->findOrFail($conversationId)->office_id;
        $office = Office::query()->findOrFail($officeId);
        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();
        $viewer->forceFill(['selected_office_id' => $office->id])->saveQuietly();

        // Viewer sem work.catalog.manage: 403 mesmo com token de outra conversa
        Sanctum::actingAs($viewer);
        $viewerConversationId = $this->postJson('/api/v1/assistant/conversations')
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/assistant/conversations/{$viewerConversationId}/approve-tool", [
            'approval_token' => $token,
        ])->assertForbidden();

        // Viewer tenta aprovar na conversa do admin → 404
        $this->postJson("/api/v1/assistant/conversations/{$conversationId}/approve-tool", [
            'approval_token' => $token,
        ])->assertNotFound();

        // Fluxo viewer: cria pending na própria conversa e tenta aprovar sem permissão
        $this->llm->enqueue([
            'tool_calls' => [[
                'id' => 'call_create_viewer',
                'name' => 'create_process_template',
                'arguments' => ['name' => 'Negado Viewer'],
            ]],
        ]);
        $viewerToken = $this->postJson("/api/v1/assistant/conversations/{$viewerConversationId}/chat", [
            'message' => 'Crie',
            'format' => 'json',
        ])->assertOk()
            ->json('data.pending_approvals.0.approval_token');

        $this->postJson("/api/v1/assistant/conversations/{$viewerConversationId}/approve-tool", [
            'approval_token' => $viewerToken,
        ])->assertForbidden();

        $this->assertDatabaseMissing('process_templates', ['name' => 'Negado Viewer']);
    }

    public function test_assistant_enabled_without_api_key_returns_503(): void
    {
        config([
            'assistant.enabled' => true,
            'assistant.openai.api_key' => '',
        ]);
        [$admin] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/assistant/conversations')
            ->assertStatus(503)
            ->assertJsonPath('code', 'ASSISTANT_DISABLED');

        $this->postJson('/api/v1/assistant/conversations', [])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ASSISTANT_DISABLED');
    }

    public function test_approve_invalid_token_returns_422_and_does_not_persist(): void
    {
        $this->enableAssistant();
        [$admin] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($admin);

        $conversationId = $this->postJson('/api/v1/assistant/conversations')
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/assistant/conversations/{$conversationId}/approve-tool", [
            'approval_token' => '00000000-0000-0000-0000-000000000000',
        ])->assertStatus(422)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.error', 'APPROVAL_INVALID');

        $this->assertSame(0, ProcessTemplate::query()->count());
    }

    public function test_deny_tool_invalidates_token_so_approve_does_not_persist(): void
    {
        $this->enableAssistant();
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($admin);

        $conversationId = $this->postJson('/api/v1/assistant/conversations')
            ->assertCreated()
            ->json('data.id');

        $this->llm->enqueue([
            'content' => 'Confirme.',
            'tool_calls' => [[
                'id' => 'call_deny_1',
                'name' => 'create_process_template',
                'arguments' => ['name' => 'Modelo Negado'],
            ]],
        ]);

        $token = $this->postJson("/api/v1/assistant/conversations/{$conversationId}/chat", [
            'message' => 'Crie Modelo Negado',
            'format' => 'json',
        ])->assertOk()
            ->json('data.pending_approvals.0.approval_token');

        $this->assertNotEmpty($token);

        $this->postJson("/api/v1/assistant/conversations/{$conversationId}/deny-tool", [
            'approval_token' => $token,
        ])->assertOk()
            ->assertJsonPath('data.status', 'denied');

        $this->postJson("/api/v1/assistant/conversations/{$conversationId}/approve-tool", [
            'approval_token' => $token,
        ])->assertStatus(422)
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseMissing('process_templates', [
            'office_id' => $office->id,
            'name' => 'Modelo Negado',
        ]);
    }

    public function test_list_tool_without_work_view_returns_403(): void
    {
        config(['features.canonical_multitenant_rbac.enabled' => true]);
        $this->enableAssistant();
        $office = Office::factory()->create();
        $user = User::factory()->create();
        $profile = TenantPermissionProfile::factory()->forOffice($office)->create();
        $profile->syncPermissionKeys([TenantPermission::TenantDashboardView]);
        OfficeMembership::factory()->create([
            'office_id' => $office->id,
            'user_id' => $user->id,
            'role' => OfficeRole::Viewer,
            'tenant_role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'authorization_version' => 1,
            'is_active' => true,
        ]);
        $user->forceFill(['selected_office_id' => $office->id])->saveQuietly();
        Sanctum::actingAs($user);

        $conversationId = $this->postJson('/api/v1/assistant/conversations')
            ->assertCreated()
            ->json('data.id');

        $this->llm->enqueue([
            'tool_calls' => [[
                'id' => 'call_list_1',
                'name' => 'list_process_templates',
                'arguments' => [],
            ]],
        ]);

        $this->postJson("/api/v1/assistant/conversations/{$conversationId}/chat", [
            'message' => 'Liste modelos',
            'format' => 'json',
        ])->assertForbidden();
    }

    private function enableAssistant(): void
    {
        config([
            'assistant.enabled' => true,
            'assistant.openai.api_key' => 'sk-test-fake',
            'assistant.openai.model' => 'gpt-4o-mini',
        ]);
    }

    /** @return array{User, Office} */
    private function actor(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, $role)->create();
        $user->forceFill(['selected_office_id' => $office->id])->saveQuietly();

        return [$user, $office];
    }
}
