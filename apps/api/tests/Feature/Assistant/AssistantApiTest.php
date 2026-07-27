<?php

namespace Tests\Feature\Assistant;

use App\Contracts\AssistantLlmGateway;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\AssistantConversation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Models\WorkProcessTemplate;
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
        [$admin] = $this->actor(TenantRole::TenantAdmin);
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
        [$admin] = $this->actor(TenantRole::TenantAdmin);
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
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->forTenant($otherTenant, TenantRole::TenantAdmin)->create();
        $otherUser->forceFill(['selected_tenant_id' => $otherTenant->id])->saveQuietly();

        Sanctum::actingAs($admin);
        $conversationId = $this->postJson('/api/v1/assistant/conversations', [
            'title' => 'Ajuda Work',
            'tenant_id' => $otherTenant->id,
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Ajuda Work')
            ->json('data.id');

        $this->assertDatabaseHas('assistant_conversations', [
            'id' => $conversationId,
            'tenant_id' => $tenant->id,
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
        [$admin] = $this->actor(TenantRole::TenantAdmin);
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
        $this->assertDatabaseMissing('work_process_templates', ['name' => 'Modelo Pendente']);
        $this->assertSame(0, WorkProcessTemplate::query()->where('name', 'Modelo Pendente')->count());
    }

    public function test_create_with_approval_and_permission_persists(): void
    {
        $this->enableAssistant();
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
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
        $this->assertDatabaseMissing('work_process_templates', ['name' => 'Modelo Aprovado']);

        $this->postJson("/api/v1/assistant/conversations/{$conversationId}/approve-tool", [
            'approval_token' => $token,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.result.name', 'Modelo Aprovado');

        $this->assertDatabaseHas('work_process_templates', [
            'tenant_id' => $tenant->id,
            'name' => 'Modelo Aprovado',
        ]);
    }

    public function test_create_approval_without_catalog_manage_returns_403(): void
    {
        $this->enableAssistant();
        [$admin] = $this->actor(TenantRole::TenantAdmin);
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

        $tenantId = AssistantConversation::query()->findOrFail($conversationId)->tenant_id;
        $tenant = Tenant::query()->findOrFail($tenantId);
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $viewer->forceFill(['selected_tenant_id' => $tenant->id])->saveQuietly();

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

        $this->assertDatabaseMissing('work_process_templates', ['name' => 'Negado Viewer']);
    }

    public function test_assistant_enabled_without_api_key_returns_503(): void
    {
        config([
            'assistant.enabled' => true,
            'assistant.openai.api_key' => '',
        ]);
        [$admin] = $this->actor(TenantRole::TenantAdmin);
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
        [$admin] = $this->actor(TenantRole::TenantAdmin);
        Sanctum::actingAs($admin);

        $conversationId = $this->postJson('/api/v1/assistant/conversations')
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/assistant/conversations/{$conversationId}/approve-tool", [
            'approval_token' => '00000000-0000-0000-0000-000000000000',
        ])->assertStatus(422)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.error', 'APPROVAL_INVALID');

        $this->assertSame(0, WorkProcessTemplate::query()->count());
    }

    public function test_deny_tool_invalidates_token_so_approve_does_not_persist(): void
    {
        $this->enableAssistant();
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
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

        $this->assertDatabaseMissing('work_process_templates', [
            'tenant_id' => $tenant->id,
            'name' => 'Modelo Negado',
        ]);
    }

    public function test_list_tool_without_work_view_returns_403(): void
    {
        $this->enableAssistant();
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $profile = TenantPermissionProfile::factory()->forTenant($tenant)->create();
        $profile->syncPermissionKeys([TenantPermission::TenantDashboardView]);
        TenantMembership::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => TenantRole::TenantUser,
            'role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'authorization_version' => 1,
            'is_active' => true,
        ]);
        $user->forceFill(['selected_tenant_id' => $tenant->id])->saveQuietly();
        Sanctum::actingAs($user);

        $conversationId = $this->postJson('/api/v1/assistant/conversations')
            ->assertCreated()
            ->json('data.id');

        $this->llm->enqueue([
            'tool_calls' => [[
                'id' => 'call_list_1',
                'name' => 'list_work_process_templates',
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

    /** @return array{User, Tenant} */
    private function actor(TenantRole $role): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, $role)->create();
        $user->forceFill(['selected_tenant_id' => $tenant->id])->saveQuietly();

        return [$user, $tenant];
    }
}
